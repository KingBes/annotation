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
     * 生成的控制器路由数据.
     */
    public static array $route = [];

    /**
     * 生成的预处理数据（预留）.
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
        foreach ($this->collectApps() as [$appPath, $appSegment]) {
            $files = [];

            // 递归扫描应用目录下所有 php 文件，仅收录包含 controller_layer 路径段的文件
            foreach ($this->phpFiles($appPath) as $file) {
                $relative = $this->relativePath($file, $appPath);
                $check = strtolower(str_replace('\\', '/', $relative));
                if (strpos($check, strtolower($this->controllerLayer)) !== false) {
                    $files[] = $file;
                }
            }

            // 额外控制器目录：归属当前应用命名空间语义，仅并入当前应用
            if ($appPath === $this->appPath) {
                foreach ((array) ($config['controllers'] ?? []) as $dir) {
                    if (!$this->isAbsolute($dir)) {
                        $dir = $this->rootPath . ltrim($dir, '/\\');
                    }
                    $dir = rtrim($dir, '/\\');
                    if (is_dir($dir)) {
                        foreach ($this->phpFiles($dir) as $file) {
                            $files[] = $file;
                        }
                    }
                }
            }

            foreach ($files as $file) {
                $this->parseFile($file, $appPath, $appSegment);
            }
        }
    }

    /**
     * 收集全部待扫描的应用目录与对应应用名段.
     *
     * 多应用下返回应用根下每个子目录；单应用下返回应用本身（应用名段为空）.
     *
     * @return array<int, array{0:string,1:string}> [应用目录, 应用名段]
     */
    protected function collectApps(): array
    {
        if ($this->appPath !== $this->basePath) {
            $apps = [];
            foreach (glob($this->basePath . '*', GLOB_ONLYDIR) as $dir) {
                $apps[] = [$dir, basename($dir)];
            }
            return $apps;
        }
        return [[$this->appPath, '']];
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
                // 跳过匿名类：类关键词后紧跟 { 则无类名
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
                break;
            }
        }
        if ($className === null) {
            return null;
        }
        return $namespace !== '' ? $namespace . '\\' . $className : $className;
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
     * @param string $appPath    所属应用目录（作为相对路径基准与命名空间根）
     * @param string $appSegment 所属应用名段（多应用下为应用名，单应用为空）
     */
    protected function parseFile(string $file, string $appPath, string $appSegment): void
    {
        $relative = $this->relativePath($file, $appPath);
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

        self::$route[] = [
            'class' => $className,
            'name' => $controllerName,
            'path' => $prefix,
            'app' => $appSegment,
            'methods' => $methods,
        ];
        self::$data[] = [
            'class' => $className,
            'name' => $controllerName,
            'path' => $prefix,
            'app' => $appSegment,
            'methods' => $methods,
        ];
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
     * 取文件相对 app 根目录的目录段，删除 controller_layer 段，
     * 其余段逐个大驼峰转下划线后串成前缀字符串（如 /admin、/v1、或空字符串）。
     *
     * @param string[] $segments 相对路径去掉文件名的目录段
     * @param string $fileName  当前文件名（含层名后缀，用于前缀末尾）
     */
    protected function prefixFromPath(array $segments, string $fileName): string
    {
        $key = strtolower($this->controllerSuffix !== '' ? $this->controllerSuffix : 'Controller');
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