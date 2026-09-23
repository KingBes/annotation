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
        foreach (Data::$route as $controller) {
            $class = $controller['class'];
            $prefix = $controller['path'];
            // 规则路径不含应用段（多应用下由 think-multi-app 剥离应用前缀后匹配）；
            // name 前缀带应用段。由于所有应用共用一个全局路由池，必须挂 AppGuard
            // 守卫，仅当请求当前应用名 == 规则所属应用名时才放行，否则会跨应用穿透。
            $app = $controller['app'] ?? '';
            $nameBase = ($app !== '' ? '/' . $app : '') . $prefix;
            foreach ($controller['methods'] as $method) {
                $methodName = $method['name'];
                $rulePath = $prefix . '/' . $this->snake($methodName);
                $this->add($route, $class, $method, $rulePath, $nameBase . '/' . $this->snake($methodName), $app);

                // index 方法额外注册去掉末尾连续 /index 段的别名路径
                if ($methodName === 'index') {
                    $alias = preg_replace('#(?:/index)+$#', '', $prefix . '/index');
                    if ($alias === '') {
                        $alias = '/';
                    }
                    $nameAlias = preg_replace('#(?:/index)+$#', '', rtrim($nameBase, '/') . '/index');
                    if ($nameAlias === '') {
                        // 剥光后为空（如根 Index::index 的 / 别名），兜底用应用标识，与 admin 对齐
                        $nameAlias = $app !== '' ? '/' . $app : 'index';
                    }
                    $this->add($route, $class, $method, $alias, $nameAlias, $app);
                }

                // 自定义 path（多应用下类型前缀同样补充应用名）
                foreach ((array) $method['path'] as $path) {
                    $this->add($route, $class, $method, $path, ($app !== '' ? '/' . $app : '') . $path, $app);
                }
            }
        }
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