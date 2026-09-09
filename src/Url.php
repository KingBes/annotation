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
                // 路由名已注册但规则含必选变量且 $vars 缺失时，框架会退化为相对解析
                // 并产出错误 URL（如 //files.download.html），此处显式抛错便于定位
                if (self::isUnbuildable($url, $vars)) {
                    throw new \InvalidArgumentException(
                        'Route name missing required variable(s): ' . $url
                    );
                }
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
     * 检测已注册路由名是否因缺少必选变量而无法生成 URL.
     *
     * 规则中 <var> 为必选变量（<var?> 为可选，不会被匹配）。已注册但每条规则的
     * 必选变量都无法由 $vars 补齐时返回 true，此时框架会跳过名称匹配退化为
     * 相对地址解析，产出错误 URL；未注册的点名不属于本方法的处理范围。
     */
    protected static function isUnbuildable(string $name, array $vars): bool
    {
        $items = \think\facade\Route::getName($name);
        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!preg_match_all('/<(\w+?)>/', (string) $item['rule'], $matches)) {
                return false; // 该条规则无必选变量，可直接生成
            }
            foreach ($matches[1] as $var) {
                if (!array_key_exists($var, $vars)) {
                    continue 2; // 该条缺变量，尝试下一条同名规则
                }
            }
            return false; // 必选变量齐全，可正常生成
        }

        return true;
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
