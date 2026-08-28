<?php

declare(strict_types=1);

namespace Kingbes\Annotation;

/**
 * 跨应用 URL 生成助手.
 *
 * ThinkPHP 多应用（think-multi-app）下，url('跨应用.路由名') 会被框架强制加上
 * 「当前应用」前缀（如当前在 install 应用时，url('admin.login.index') 会错误生成为
 * /install/login/index）。
 *
 * 本助手分两步处理：
 * 1. 跨应用路由名（首段是其它应用目录，如 admin.login.index）→ 在交予框架前转为绝对路径
 * 2. 全局路由名（首段不是应用目录，如 files.download）→ 生成后去掉框架强加的当前应用前缀
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
     * 生成 URL（等价框架 url()，但支持跨应用/全局点分路由名）.
     *
     * @return string
     */
    public static function build(string $url = '', array $vars = [], $suffix = true, $domain = false): string
    {
        $normalized = self::normalize($url);
        $obj = \think\facade\Route::buildUrl($normalized, $vars)
            ->suffix($suffix)
            ->domain($domain);

        // normalize 未转换且是点分路由名时，框架会给全局路由名匹配结果强加当前应用前缀
        if ($normalized === $url && self::isDotName($url)) {
            $target  = explode('.', $url)[0];
            $current = app('http')->getName();

            if ($target !== $current) {
                return self::stripAppPrefix((string)$obj, $current);
            }
        }

        return (string)$obj;
    }

    /**
     * 将跨应用点分路由名转换为绝对路径.
     *
     * 仅在「首段是存在的应用目录且不属于当前应用」时转换；同应用、绝对路径、相对地址原样返回。
     */
    protected static function normalize(string $url): string
    {
        if (!self::isDotName($url)) {
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

    /**
     * 判断是否为点分路由名（不含 / 但含 .）.
     */
    protected static function isDotName(string $url): bool
    {
        return false !== strpos($url, '.') && false === strpos($url, '/');
    }

    /**
     * 去掉 multi-app 强加的当前应用前缀.
     *
     * 仅替换 URL 路径中第一次出现的 {app}/ 段（开头或紧跟 / 之后）。
     */
    protected static function stripAppPrefix(string $url, string $app): string
    {
        $pattern = '/(^|\/)' . preg_quote($app, '/') . '\//';
        return preg_replace($pattern, '$1', $url, 1);
    }
}
