<?php

declare(strict_types=1);

namespace Kingbes\Annotation;

use think\route\Url as UrlBuild;

/**
 * 跨应用 URL 生成助手.
 *
 * ThinkPHP 多应用（think-multi-app）下，url('跨应用.路由名') 会被框架强制加上
 * 「当前应用」前缀（如当前在 install 应用时，url('admin.login.index') 会错误生成为
 * /install/login/index）。本助手在交予框架前，先把「首段为其它应用目录的点分路由名」
 * 转为绝对路径，从而生成正确 URL。
 *
 * 用法：在应用公共文件 common.php（需早于框架 helper.php 加载）中覆盖 url()：
 *
 *     if (!function_exists('url')) {
 *         function url(string $url = '', array $vars = [], $suffix = true, $domain = false)
 *         {
 *             return \Kingbes\Annotation\Url::build($url, $vars, $suffix, $domain);
 *         }
 *     }
 */
class Url
{
    /**
     * 生成 URL（等价框架 url()，但支持跨应用点分路由名）.
     *
     * @return UrlBuild
     */
    public static function build(string $url = '', array $vars = [], $suffix = true, $domain = false): UrlBuild
    {
        return \think\facade\Route::buildUrl(self::normalize($url), $vars)
            ->suffix($suffix)
            ->domain($domain);
    }

    /**
     * 将跨应用点分路由名转换为绝对路径.
     *
     * 仅在「首段是存在的应用目录且不属于当前应用」时转换；同应用、绝对路径、相对地址原样返回。
     */
    protected static function normalize(string $url): string
    {
        // 仅处理「不含 / 但含 .」的点分路由名
        if (false === strpos($url, '.') || false !== strpos($url, '/')) {
            return $url;
        }

        $segments = explode('.', $url);
        $target   = $segments[0] ?? '';
        if ($target === '') {
            return $url;
        }

        // 目标应用与当前应用一致时维持框架原行为
        if ($target === app('http')->getName()) {
            return $url;
        }

        // 首段为存在的应用目录，视为跨应用点名，转绝对路径
        if (is_dir(app()->getBasePath() . $target . DIRECTORY_SEPARATOR)) {
            return '/' . implode('/', $segments);
        }

        return $url;
    }
}