<?php

declare(strict_types=1);

namespace Kingbes\Annotation;

use think\Response;
use think\Service as BaseService;
use think\event\RouteLoaded;

/**
 * 路由注册服务，监听 RouteLoaded 事件注册注解路由。
 */
class Service extends BaseService
{
    public function boot(): void
    {
        $this->app->event->listen(RouteLoaded::class, function () {
            $config = (array) $this->app->config->get('annotation', []);
            if (empty($config['enable'])) {
                return;
            }
            // 多应用下 Http::loadRoutes() 只加载 app/{应用}/route/，全局 route/*.php 被跳过；
            // 此处补加载全局路由文件，使其中定义的路由名（如 files.download）在请求中可用
            $this->loadGlobalRoutes();
            new Data($this->app, $config);
            $this->registerAnnotationRoutes();
        });
    }

    /**
     * 加载全局路由文件（项目根 route/*.php）.
     *
     * 多应用下 Http::loadRoutes() 的 routePath 被设置为 app/{应用}/route/，
     * 全局 route/ 目录被跳过。用 include_once 避免与框架重复加载（单应用下框架
     * 已用 include 加载过，include_once 不会二次执行）。
     */
    protected function loadGlobalRoutes(): void
    {
        $routePath = $this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR;
        if (!is_dir($routePath)) {
            return;
        }
        foreach (glob($routePath . '*.php') as $file) {
            include_once $file;
        }
    }

    protected function registerAnnotationRoutes(): void
    {
        $route = $this->app->route;
        // 多应用下 think-multi-app 在派发前剥离 URL 首段（应用名）再做路由匹配，
        // 因此所有应用共用一个全局路由池时，两个应用若都有同名控制器（如 Index），
        // 其应用内路径（/、index/index 等）会重复注册，而 TP 首条匹配即胜出、
        // 守卫中间件来不及区分，导致默认应用路由被其它应用规则截胡（跨应用穿透）。
        //
        // 修复：应用已绑定时（HTTP 请求）只注册「当前应用」的路由，从根本上杜绝
        // 同路径跨应用碰撞；未绑定时（CLI，如 php think route:list）注册全部应用，
        // 便于开发期查看完整路由表。该策略同时让 AppGuard 退化为兜底防线。
        $currentApp = $this->app->http->getName();

        foreach (Data::$route as $controller) {
            $class = $controller['class'];
            $prefix = $controller['path'];
            $app = $controller['app'] ?? '';
            if ($currentApp !== '' && $app !== $currentApp) {
                continue; // 应用隔离：仅注册当前应用的路由
            }
            $nameBase = ($app !== '' ? '/' . $app : '') . $prefix;
            foreach ($controller['methods'] as $method) {
                $methodName = $method['name'];

                if ($methodName === 'index') {
                    // index 方法作为应用/控制器的默认入口，注册多级「去掉末尾 /index」别名，
                    // 使 /、/index、/index/index、/index/index/index 等形态都能落到 Index@index；
                    // 非默认应用同理（/admin、/admin/index、/admin/index/index）。
                    foreach ($this->indexAliasPaths($prefix) as $aliasPath) {
                        $name = ($app !== '' ? '/' . $app : '') . $aliasPath;
                        $this->add($route, $class, $method, $aliasPath, $name, $app);
                    }
                    continue;
                }

                $rulePath = $prefix . '/' . $this->snake($methodName);
                $this->add($route, $class, $method, $rulePath, $nameBase . '/' . $this->snake($methodName), $app);

                // 自定义 path（多应用下类型前缀同样补充应用名）
                foreach ((array) $method['path'] as $path) {
                    $this->add($route, $class, $method, $path, ($app !== '' ? '/' . $app : '') . $path, $app);
                }
            }
        }
    }

    /**
     * 计算 index 方法的全部应用内路径别名.
     *
     * 从「前缀/index」出发，逐级剥掉末尾 /index 段（/index/index → /index → /），
     * 再追加一级（覆盖显式写出应用名如 /index/index/index 的入口），最终得到
     * 一组互不相同的应用内路径，供多应用下各形态的默认入口 URL 命中。
     *
     * @return string[]
     */
    protected function indexAliasPaths(string $prefix): array
    {
        // $prefix 形如 /index、/admin/index、/v1/article（应用内路径，不含应用段）。
        // 以「前缀/index」为起点，逐级剥掉末尾 /index 段（/index/index → /index → /），
        // 再追加一级（覆盖 /index/index/index 这类显式入口），最终得到一组互不相同的
        // 应用内路径，供多应用下各形态的默认入口 URL 命中。
        //
        // 注意：剥到只剩 /index 时正则会剥出空串 ''，必须归一为 '/'。空串规则是
        // 语义错误（根路径应注册成 '/'），统一在循环内把空串收口为 '/'，使根别名
        // 始终为规范的根规则。（注：带后缀的 /.html 在 TP8 下仍由框架层判 404，
        // 与路由规则无关——原生 Route::get('/', ...) 同样如此，属框架已知行为。）
        $main = rtrim($prefix, '/') . '/index';
        $paths = [$main];
        $cur = $main;
        while (true) {
            $next = preg_replace('#/index$#', '', $cur, 1);
            if ($next === '') {
                $next = '/'; // 剥到 /index 时收口为根，避免空串规则
            }
            if ($next === $cur) {
                break; // 末尾无 /index 可剥，停止
            }
            $paths[] = $next;
            if ($next === '/') {
                break;
            }
            $cur = $next;
        }
        $paths[] = $main . '/index'; // 追加一级：/index/index/index 这类显式应用名入口

        return array_values(array_unique($paths));
    }

    protected function add($route, string $class, array $method, string $rulePath, string $namePath, string $app = ''): void
    {
        $request = $method['request'] ?? '*';
        if (empty($request)) {
            $request = '*';
        }
        // ThinkPHP 的 rule() 要求 method 为字符串，多个方法用 | 分隔
        if (is_array($request)) {
            $request = implode('|', array_map('strtoupper', $request));
        } elseif ($request !== '*') {
            $request = strtoupper((string) $request);
        }
        // 路由到类的方法：\完整类名@方法名（见官方 路由地址 文档）
        $rule = $route->rule($rulePath, '\\' . $class . '@' . $method['name'], $request);
        // 跨应用穿透守卫：多应用下其它应用的 URL 不应命中本规则（先短路，再跑用户中间件）
        if ($app !== '') {
            $rule->middleware($this->appGuard($app));
        }
        foreach ((array) ($method['middleware'] ?? []) as $middleware) {
            $rule->middleware($middleware);
        }
        // 路由标识：自定义 route_name 优先，否则取带应用段的完整路径转点号，空名不设置
        $name = $method['route_name'] ?? $this->toName($namePath);
        if ($name !== '') {
            $rule->name($name);
        }
    }

    /**
     * 构造跨应用路由穿透守卫闭包.
     *
     * 多应用下所有应用的注解路由注册进同一个全局路由池、规则路径不含应用段，
     * 若不加以限制，A 应用的 URL 可命中 B 应用的规则（后台接口未授权可达、
     * 前台控制器在后台上下文执行并报 template not exists）。
     *
     * 每条规则在扫描时已记录其所属应用（$app），此处把 $app 与「请求当前应用名」
     * 比对，不一致则直接 404。单应用（$app 为空）不挂载，无额外开销。
     *
     * 保留全局注册是为了 url('跨应用路由名') 仍能解析——名称全量存在，
     * 仅分发阶段受此守卫约束（不会破坏跨应用链接生成）。
     *
     * @return \Closure
     */
    protected function appGuard(string $app): \Closure
    {
        return function (\think\Request $request, \Closure $next) use ($app) {
            $current = $this->app->http->getName() ?: 'index';
            if ($app !== $current) {
                return Response::create('Not Found', 'html', 404);
            }
            return $next($request);
        };
    }

    protected function toName(string $path): string
    {
        return str_replace('/', '.', trim($path, '/'));
    }

    protected function snake(string $name): string
    {
        // 大驼峰转下划线，同时兼容已为下划线命名的方法
        $result = '';
        $len = strlen($name);
        for ($i = 0; $i < $len; $i++) {
            $char = $name[$i];
            if ($i > 0 && ctype_upper($char)) {
                $result .= '_';
            }
            $result .= strtolower($char);
        }
        return $result;
    }
}