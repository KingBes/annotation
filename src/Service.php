<?php

declare(strict_types=1);

namespace Kingbes\Annotation;

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
            // 规则路径不带应用名；name 前缀带应用名（多应用下由 URL 生成器补充应用段）
            $app = $controller['app'] ?? '';
            $nameBase = ($app !== '' ? '/' . $app : '') . $prefix;
            foreach ($controller['methods'] as $method) {
                $methodName = $method['name'];
                $rulePath = $prefix . '/' . $this->snake($methodName);
                $this->add($route, $class, $method, $rulePath, $nameBase . '/' . $this->snake($methodName));

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
                    $this->add($route, $class, $method, $alias, $nameAlias);
                }

                // 自定义 path（多应用下类型前缀同样补充应用名）
                foreach ((array) $method['path'] as $path) {
                    $this->add($route, $class, $method, $path, ($app !== '' ? '/' . $app : '') . $path);
                }
            }
        }
    }

    protected function add($route, string $class, array $method, string $rulePath, string $namePath): void
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
        foreach ((array) ($method['middleware'] ?? []) as $middleware) {
            $rule->middleware($middleware);
        }
        // 路由标识：自定义 route_name 优先，否则取带应用段的完整路径转点号，空名不设置
        $name = $method['route_name'] ?? $this->toName($namePath);
        if ($name !== '') {
            $rule->name($name);
        }
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