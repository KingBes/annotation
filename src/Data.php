<?php

declare(strict_types=1);

namespace Kingbes\Annotation;

use think\App;

/**
 * 注解扫描器.
 *
 * 扫描控制器目录、反射类与方法、合并可重复注解，最终生成路由数据。
 * 同时兼容单应用与多应用两种目录结构。
 */
class Data
{
    /**
     * 控制器项保留键（类级注解平铺时不覆盖这些键）.
     */
    protected const RESERVED_KEYS = ['class', 'name', 'path', 'app', 'methods'];

    /**
     * 控制器目录配置键（如 controller）.
     */
    protected string $controllerLayer;

    /**
     * 控制器后缀（如 Controller）.
     */
    protected string $controllerSuffix;

    /**
     * 应用根目录（app 根目录，容纳全部应用与 controller）.
     */
    protected string $basePath;

    /**
     * 当前应用目录（多应用下为 app/{应用名}）.
     */
    protected string $appPath;

    /**
     * 项目根目录.
     */
    protected string $rootPath;

    /**
     * 是否启用扫描缓存.
     */
    protected bool $cache;

    /**
     * 扫描缓存文件.
     */
    protected string $cacheFile;

    /**
     * 生成的控制器路由数据.
     */
    public static array $route = [];

    /**
     * 生成的全部注解数据（结构与 $route 一致，供业务读取）.
     */
    public static array $data = [];

    public function __construct(App $app, array $config = [])
    {
        self::$route = [];
        self::$data = [];

        $this->controllerLayer = (string) $app->config->get('route.controller_layer', 'controller');
        $this->controllerSuffix = (string) $app->config->get('route.controller_suffix', '');
        $this->basePath = $app->getBasePath();
        $this->appPath = $app->getAppPath();
        $this->rootPath = $app->getRootPath();

        // 未显式配置时跟随 app_debug：调试模式不缓存，避免改了注解不生效
        $this->cache = (bool) ($config['cache'] ?? !$app->isDebug());
        $this->cacheFile = rtrim($app->getRuntimePath(), '/\\') . DIRECTORY_SEPARATOR . 'annotation.php';

        $this->scan($config);
    }

    /**
     * 扫描目录并生成路由数据.
     *
     * 一次遍历 app 根目录下的全部应用（多应用下为各子应用，单应用下为应用本身），
     * 使跨应用的控制器路由（含路由名）都能注册，便于 url('跨应用路由名') 解析。
     */
    protected function scan(array $config): void
    {
        $files = $this->collectFiles($config);
        $signature = $this->signature($files);

        if (!$this->fromCache($signature)) {
            foreach ($files as $item) {
                $this->parseFile($item['file'], $item['base'], $item['app']);
            }
            $this->toCache($signature);
        }

        self::$data = self::$route;
    }

    /**
     * 收集全部待扫描的文件及其「相对路径基准目录」与「所属应用名段」.
     *
     * @return array<int, array{file:string,base:string,app:string}>
     */
    protected function collectFiles(array $config): array
    {
        $files = [];

        foreach ($this->collectApps() as [$appPath, $appSegment]) {
            foreach ($this->controllerFiles($appPath) as $file) {
                $files[] = ['file' => $file, 'base' => $appPath, 'app' => $appSegment];
            }
        }

        // 额外控制器目录：归属当前应用，路径以该目录自身为基准。
        // 这里必须用目录自身做基准，否则目录不在当前应用下时
        // relativePath() 匹配不到前缀，会返回整条磁盘路径当路由前缀。
        foreach ((array) ($config['controllers'] ?? []) as $dir) {
            if (!$this->isAbsolute($dir)) {
                $dir = $this->rootPath . ltrim($dir, '/\\');
            }
            $dir = rtrim($dir, '/\\');
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->phpFiles($dir) as $file) {
                $files[] = ['file' => $file, 'base' => $dir, 'app' => $this->currentAppSegment()];
            }
        }

        return $files;
    }

    /**
     * 收集全部待扫描的应用目录与对应应用名段.
     *
     * 判定顺序：
     * 1. 框架已切换到具体应用（多应用 HTTP 请求）→ 遍历全部应用目录
     * 2. CLI 或单应用：确认是多应用结构时才按应用拆分，
     *    使 php think route:list 与真实请求得到一致的规则与 app 字段
     * 3. 其余按单应用处理
     *
     * @return array<int, array{0:string,1:string}> [应用目录, 应用名段]
     */
    protected function collectApps(): array
    {
        $dirs = glob($this->basePath . '*', GLOB_ONLYDIR) ?: [];

        if (!$this->isBaseAppPath()) {
            $apps = [];
            foreach ($dirs as $dir) {
                $apps[] = [$dir, basename($dir)];
            }
            return $apps;
        }

        // appPath === basePath：CLI 下 multi-app 尚未切换 appPath，
        // 此时若按单应用处理，路由前缀会把应用目录段拼进去，与 HTTP 请求结果不一致
        if ($this->hasMultiApp()) {
            $apps = [];
            foreach ($dirs as $dir) {
                if ($this->isAppDir($dir)) {
                    $apps[] = [$dir, basename($dir)];
                }
            }
            if ($apps !== []) {
                return $apps;
            }
        }

        return [[$this->appPath, '']];
    }

    /**
     * 当前应用目录是否仍是 app 根目录（即未切换到具体应用）.
     */
    protected function isBaseAppPath(): bool
    {
        return rtrim($this->appPath, '/\\') === rtrim($this->basePath, '/\\');
    }

    /**
     * 当前应用名段（单应用或未切换应用时为空）.
     */
    protected function currentAppSegment(): string
    {
        if ($this->isBaseAppPath()) {
            return '';
        }
        return basename(rtrim($this->appPath, '/\\'));
    }

    /**
     * 是否安装了多应用扩展.
     */
    protected function hasMultiApp(): bool
    {
        return class_exists(\think\app\MultiApp::class);
    }

    /**
     * 目录是否为应用目录（直接包含 controller_layer 子目录）.
     */
    protected function isAppDir(string $dir): bool
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (strtolower(basename($sub)) === strtolower($this->controllerLayer)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 收集应用内的控制器文件.
     *
     * 常规结构（{应用}/{controller_layer}/）直接从该层目录递归，
     * 避免把 model / view 等同级目录也遍历一遍——这是扫描阶段最大的一笔开销。
     * 非常规结构（controller_layer 嵌在更深层）才退回全量递归 + 路径段过滤。
     *
     * @return string[]
     */
    protected function controllerFiles(string $appPath): array
    {
        $layerDir = rtrim($appPath, '/\\') . DIRECTORY_SEPARATOR . $this->controllerLayer;
        if (is_dir($layerDir)) {
            return $this->phpFiles($layerDir);
        }

        $files = [];
        foreach ($this->phpFiles($appPath) as $file) {
            if ($this->inControllerLayer($file, $appPath)) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * 判断文件是否位于 controller_layer 目录段之下（按路径段精确匹配）.
     */
    protected function inControllerLayer(string $file, string $base): bool
    {
        $relative = strtolower(str_replace('\\', '/', $this->relativePath($file, $base)));
        $layer = strtolower($this->controllerLayer);
        foreach (explode('/', $relative) as $segment) {
            if ($segment === $layer) {
                return true;
            }
        }
        return false;
    }

    /**
     * 递归收集目录下所有 php 文件.
     */
    protected function phpFiles(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                $files[] = $item->getPathname();
            }
        }
        return $files;
    }

    /**
     * 判断路径是否为绝对路径.
     */
    protected function isAbsolute(string $path): bool
    {
        return preg_match('#^(/|[a-zA-Z]:[\\\\/])#', $path) === 1;
    }

    /**
     * 从文件内容解析真实类名（namespace + className）.
     *
     * 不依赖文件名推导，避免文件名与类名不一致（如 Install.php 内定义 Index 类）时漏扫。
     * 跳过匿名类与 `Foo::class` 中的 class 关键字，取第一个具名类。
     *
     * @return string|null 完整类名，无法解析时返回 null
     */
    protected function resolveClassFromFile(string $file): ?string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $namespace = '';
        $className = null;
        $tokens = token_get_all($content);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $buffer = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if ($t === ';') {
                        break;
                    }
                    if (is_array($t)) {
                        $buffer .= $t[1];
                    }
                }
                $namespace = trim($buffer);
                continue;
            }
            if ($token[0] === T_CLASS) {
                // 跳过 Foo::class 里的 class 关键字
                if ($this->isClassConstantFetch($tokens, $i)) {
                    continue;
                }
                // 类关键词后紧跟 { 则为匿名类，继续找下一个具名类
                for ($k = $i + 1; $k < $count; $k++) {
                    $t = $tokens[$k];
                    if ($t === '{') {
                        break;
                    }
                    if (is_array($t) && $t[0] === T_STRING) {
                        $className = $t[1];
                        break;
                    }
                }
                if ($className !== null) {
                    break;
                }
                continue;
            }
        }
        if ($className === null) {
            return null;
        }
        return $namespace !== '' ? $namespace . '\\' . $className : $className;
    }

    /**
     * 判断 T_CLASS 是否为 `::class` 常量取名的 class 关键字.
     *
     * @param array<int, mixed> $tokens
     */
    protected function isClassConstantFetch(array $tokens, int $index): bool
    {
        for ($p = $index - 1; $p >= 0; $p--) {
            $prev = $tokens[$p];
            if (is_array($prev) && in_array($prev[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($prev) && $prev[0] === T_DOUBLE_COLON;
        }
        return false;
    }

    /**
     * 获取文件相对目录的路径（统一为 / 分隔，去掉根前缀）.
     */
    protected function relativePath(string $file, string $dir): string
    {
        $file = str_replace(['/', '\\'], '/', $file);
        $dir = rtrim(str_replace(['/', '\\'], '/', $dir), '/');
        return ltrim(str_replace($dir, '', $file), '/');
    }

    /**
     * 解析单个控制器文件，类名取自文件内容，反射生成路由数据.
     *
     * @param string $file       控制器文件绝对路径
     * @param string $base       相对路径基准目录（应用目录或额外控制器目录）
     * @param string $appSegment 所属应用名段（多应用下为应用名，单应用为空）
     */
    protected function parseFile(string $file, string $base, string $appSegment): void
    {
        $relative = $this->relativePath($file, $base);
        if ($relative === '') {
            return;
        }
        $path = substr($relative, 0, -4); // 去掉 .php
        $segments = explode('/', $path);
        array_pop($segments); // 弹出文件名，仅保留目录段（如 install/controller）

        // 从文件内容解析真实类名，避免文件名 != 类名时漏扫（如 Install.php 内定义 Index 类）
        $className = $this->resolveClassFromFile($file);
        if ($className === null || !class_exists($className)) {
            return;
        }

        $reflection = new \ReflectionClass($className);
        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
            return;
        }

        // 控制器名：类短名去掉 controller_suffix（未配置则默认去掉尾部 Controller）
        $suffix = $this->controllerSuffix !== '' ? $this->controllerSuffix : 'Controller';
        $shortName = $reflection->getShortName();
        if ($suffix !== '' && substr($shortName, -strlen($suffix)) === $suffix) {
            $shortName = substr($shortName, 0, -strlen($suffix));
        }
        $controllerName = $this->bc2us($shortName);

        // 读取可重复注解（含类级）
        $classAnnotations = $this->mergeAnnotations($reflection->getAttributes(Annotation::class));

        // 计算默认路径前缀
        $prefix = $this->prefixFromPath($segments, $shortName);

        // 遍历公共方法
        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            $methodName = $method->getName();
            if ($methodName === '__construct' || $methodName === '__destruct') {
                continue;
            }

            // 合并类级与方法级注解，保留全部自定义键（如 title、auth、other 等）
            $merged = array_replace($classAnnotations, $this->mergeAnnotations($method->getAttributes(Annotation::class)));

            // 规范化路由字段：path / request / middleware / name(=方法名) / route_name(自定义名)
            $methodData = $merged;
            $methodData['path'] = $merged['path'] ?? [];
            $methodData['request'] = $merged['request'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
            $methodData['middleware'] = (array) ($merged['middleware'] ?? []);
            $methodData['name'] = $methodName;
            if (isset($merged['name'])) {
                $methodData['route_name'] = $merged['name'];
            }

            $methods[] = $methodData;
        }

        $controller = [
            'class' => $className,
            'name' => $controllerName,
            'path' => $prefix,
            'app' => $appSegment,
        ];
        // 类级注解（title / icon / auth 等）平铺到控制器项，便于直接读取；
        // 与路由逻辑同名的键（path / name 等）不覆盖保留字段，仍以 methods 中的合并结果为准
        foreach ($classAnnotations as $key => $value) {
            if (!in_array($key, self::RESERVED_KEYS, true)) {
                $controller[$key] = $value;
            }
        }
        $controller['methods'] = $methods;

        self::$route[] = $controller;
    }

    /**
     * 合并可重复注解.
     *
     * @param \ReflectionAttribute[] $attributes
     */
    protected function mergeAnnotations(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $attribute) {
            $data = $attribute->newInstance()->get();
            foreach ($data as $key => $value) {
                if ($key === 'path') {
                    $result['path'] = array_merge((array) ($result['path'] ?? []), (array) $value);
                } else {
                    $result[$key] = $value; // 标量键后者覆盖前者
                }
            }
        }
        return $result;
    }

    /**
     * 推导默认路径前缀（统一兼容单/多应用）.
     *
     * 取文件相对基准目录的目录段，删除 controller_layer 段，
     * 其余段逐个大驼峰转下划线后串成前缀字符串（如 /admin、/v1、或空字符串）。
     *
     * @param string[] $segments 相对路径去掉文件名的目录段
     * @param string $fileName  当前文件名（含层名后缀，用于前缀末尾）
     */
    protected function prefixFromPath(array $segments, string $fileName): string
    {
        $prefixSegments = [];
        foreach ($segments as $segment) {
            if (strtolower($segment) === strtolower($this->controllerLayer)) {
                continue; // 删除 controller_layer 段
            }
            $prefixSegments[] = $this->bc2us($segment);
        }
        // 末尾追加文件名（类名）段
        $name = $fileName;
        if ($this->controllerSuffix !== '' && substr($name, -strlen($this->controllerSuffix)) === $this->controllerSuffix) {
            $name = substr($name, 0, -strlen($this->controllerSuffix));
        } else {
            $name = preg_replace('/[Cc]ontroller$/', '', $name) ?? $name;
        }
        $prefixSegments[] = $this->bc2us($name);

        return '/' . implode('/', $prefixSegments);
    }

    /**
     * 扫描指纹：文件清单、路径基准、应用段与 mtime 共同决定缓存是否失效.
     *
     * @param array<int, array{file:string,base:string,app:string}> $files
     */
    protected function signature(array $files): string
    {
        $items = [$this->controllerLayer, $this->controllerSuffix];
        foreach ($files as $item) {
            $items[] = $item['file'] . '|' . $item['base'] . '|' . $item['app'] . '|' . (string) @filemtime($item['file']);
        }
        return md5(serialize($items));
    }

    /**
     * 读取缓存，命中则填充 self::$route.
     */
    protected function fromCache(string $signature): bool
    {
        if (!$this->cache || !is_file($this->cacheFile)) {
            return false;
        }
        $cached = @include $this->cacheFile;
        if (!is_array($cached) || ($cached['signature'] ?? '') !== $signature) {
            return false;
        }
        self::$route = (array) ($cached['route'] ?? []);
        return true;
    }

    /**
     * 写入扫描缓存.
     */
    protected function toCache(string $signature): void
    {
        if (!$this->cache) {
            return;
        }
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $payload = ['signature' => $signature, 'route' => self::$route];
        @file_put_contents($this->cacheFile, '<?php return ' . var_export($payload, true) . ';');
    }

    /**
     * 大驼峰转下划线.
     */
    public function bc2us(string $string): string
    {
        $newString = '';
        $len = strlen($string);
        for ($i = 0; $i < $len; $i++) {
            $char = $string[$i];
            if ($i > 0 && ctype_upper($char)) {
                $newString .= '_';
            }
            $newString .= strtolower($char);
        }
        return strtolower($newString);
    }
}
