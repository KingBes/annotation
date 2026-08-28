# kingbes/annotation

基于 ThinkPHP 8 的 PHP 8 原生 注解 路由方案。

> 使用一个通用的 `#[Annotation]` 注解即可声明路由、请求方法、中间件、路由名，同时兼容单应用与多应用的目录结构。

## 环境要求

- PHP >= 8.0
- ThinkPHP 8

## 安装

```bash
composer require kingbes/annotation
```

安装完成后，插件会自动注册 `RouteLoaded` 事件监听，无需手动绑定。

## 配置

插件通过 `src/config.php` 提供配置（由 composer 的 `extra.think.config` 自动加载，无需手动发布）：

| 配置 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `enable` | `bool` | `true` | 总开关，`false` 时关闭注解路由 |
| `controllers` | `array` | `[]` | 额外扫描的控制器目录（绝对路径，或相对项目根的路径） |

默认扫描目录为应用的控制器目录（`app/**/controller`）。如需覆盖配置，在项目 `config/annotation.php` 中写同名键即可（ThinkPHP 会自动合并）：

```php
// config/annotation.php
return [
    'enable' => true,
    'controllers' => [],
];
```

## 快速开始

在控制器类或方法上使用 `#[Annotation([...])]` 声明路由信息。

```php
<?php

namespace app\controller;

use Kingbes\Annotation\Annotation;

#[Annotation([
    'title' => '首页',
])]
class User
{
    // GET /user/get_list
    #[Annotation([
        'path' => [],
        'title' => '用户列表',
        'request' => ['GET', 'POST'],
        'middleware' => ['CheckAuth'],
        'name' => 'user.getList',
    ])]
    public function getList()
    {
        return 'user list';
    }

    public function index()
    {
        return 'index';
    }
}
```

说明：

- 类 `User` 对应类名去掉尾部 `Controller` 后再做 `大驼峰转下划线` 的控制器名，即 `UserController` → `user`。
- 若你的类本身就叫 `UserController`（未配置 `route.controller_suffix`），默认会自动去掉尾部字面量 `Controller`；若你配置了 `route.controller_suffix = 'Controller'`，则按配置为准。
- 上面的类名为 `User`、方法名为 `getList` 时，默认路由为 `/user/get_list`。

## 注解参数

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `title` | `string` | 无 | 路由标题/说明（仅注释性质） |
| `path` | `array` | `[]` | 自定义附加路由路径，会额外注册 |
| `request` | `array`|string | `['GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS']` | 允许的请求方法 |
| `middleware` | `array`|string | `[]` | 中间件列表 |
| `name` | `string` | 生成的点号路径（如 `user.get_list`） | 自定义路由名；未设置时默认用「路径去首尾 `/` 并把 `/` 换成 `.`」，空路径（如 `/` 别名）不设名 |

> 注意：`name` 会作为路由标识（`route_name`）；`path` / `request` / `middleware` / `name` 参与路由注册与解析。

## 自定义属性

`#[Annotation]` 接收任意数组，除上述标准键外，**其它自定义键会原样保存**，方便携带权限标识、业务标记等自定义数据（仅记录，不参与路由逻辑）。例如：

```php
#[Annotation([
    'title' => '删除用户',
    'auth'  => 'admin',
    'other' => ['Asd'],   // 自定义键，会保留在 Data 中
])]
public function delete()
{
}
```

访问全部注解信息仍使用 `Data::$data` / `Data::$route`，`methods` 内会包含这些自定义键。

> 注意：注解数组键值必须使用 `=>` 语法（原生 PHP Attribute 只接受常量表达式，键与值请写在同一数组字面量中）。

## 可重复注解

`#[Annotation]` 声明为 `IS_REPEATABLE`，可以在同一个类或方法上叠加多个注解，扫描时会自动合并。

- 标量键：后者的值覆盖前者。
- `path` 键：多次书写会以追加方式合并。

```php
#[Annotation(['request' => ['GET']])]
#[Annotation(['path' => ['/user/list']])]
public function getList() {}
```

## 获取全部注解信息

通过 `Data` 类获取扫描到的所有注解信息：

```php
use Kingbes\Annotation\Data;

Data::$data;   // 全部注解数据（class / name / path / methods）
Data::$route;  // 路由数据（同上，供路由注册使用）
```

其中 `methods` 为每个方法的 `path` / `request` / `middleware` / `name` / `route_name`。

## 默认路由规则

默认路径由「控制器文件在**当前应用**内相对 `controller_layer` 的目录段 + 控制器名 + 方法名」推导而来，`controller_layer`（默认 `controller`）及之前的段会被剔除；子目录段逐段转下划线后拼接。**路由规则本身不含应用名**。

- 单应用：扫描当前应用的 `controller` 目录，无需应用段。
- 多应用（`topthink/think-multi-app`）：每个应用只扫描并注册**自己**的控制器，规则不带应用名；实际访问 URL 由多应用机制自动带上应用段。
- 路由标识 `name` 在多应用下会带上应用段装饰（如 `admin.user.get_list`），可用 `url('admin.user.get_list')` 生成 URL。

### 单应用

| 注解位置 | 默认路由规则 | 默认 name |
| --- | --- | --- |
| `app/controller/UserController::getList` | `/user/get_list` | `user.get_list` |
| `app/controller/Index::index` | `/index/index` | `index.index` |

### 多应用

| 注解位置 | 注册的路由规则 | 实际访问 URL | 默认 name |
| --- | --- | --- | --- |
| `app/index/controller/Diary::index` | `/diary/index` | `/index/diary/index` | `index.diary.index` |
| `app/admin/controller/UserController::getList` | `/user/get_list` | `/admin/user/get_list` | `admin.user.get_list` |

### index 别名

`index` 方法会自动额外注册一条去掉末尾连续 `/index` 段的别名路径。例如：

- `Index::index`：主路由 `/index/index`，别名 `/index`。
- `app/index/controller/Diary::index`：主路由 `/diary/index`，别名 `/diary`。访问 `/index/diary` → `/index/diary/index`。

## 跨应用 URL 生成

ThinkPHP 多应用（`topthink/think-multi-app`）下，`url('跨应用.路由名')` 会被框架**强制加上「当前应用」前缀**。例如当前在 `install` 应用时调用 `url('admin.login.index')`，框架会用 `rule` 路径 `/login/index` 拼上当前应用名，错误生成为 `/install/login/index`。

插件提供 `Kingbes\Annotation\Url::build()`，分两步处理：

1. **跨应用路由名**（首段是其它应用目录，如 `admin.login.index`）→ 转为绝对路径 `/admin/login/index`，走框架绝对路径分支
2. **全局路由名**（首段不是应用目录，如 `files.download`）→ 生成后去掉框架强加的当前应用前缀

要全局生效，请在应用公共文件 `common.php` 中覆盖 `url()`：

```php
<?php
// 位于 应用/common.php 或 项目根/common.php
if (!function_exists('url')) {
    function url(string $url = '', array $vars = [], $suffix = true, $domain = false)
    {
        return \Kingbes\Annotation\Url::build($url, $vars, $suffix, $domain);
    }
}
```

> 覆盖生效前提：该 `common.php` 需**先于框架 `helper.php` 加载**。ThinkPHP 的 `App::load()` 先 `include` 应用 `common.php`、再 `include` 框架 `helper.php`，因此应用 `common.php` 中的定义会优先生效。这个由 `common.php` 放置位置决定：

- 单应用：`app/common.php`。
- 多应用：默认只会加载当前应用目录下的 `common.php`，若每个应用都要用，可在每个应用放一份，或放到各应用共同引入的位置。

覆盖后，以下调用均正确：

| 调用 | 生成 |
| --- | --- |
| `url('admin.login.index')` | `/admin/login/index`（跨应用，转绝对路径） |
| `url('admin.index.menu')` | `/admin/index/menu`（跨应用） |
| `url('files.download', ['id'=>1, 'ext'=>'png'])` | `/files/1.png`（全局路由名，去掉应用前缀） |
| `url('/admin/login/index')` | `/admin/login/index`（绝对路径本就正确） |
| 当前应用下的 `url('admin.index.menu')` | `admin` 应用内调用时维持框架原行为 |

### 多应用下全局路由文件加载

think-multi-app 会把 `Http::loadRoutes()` 的路由目录改为 `app/{应用}/route/`，导致项目根 `route/*.php`（如自定义的 `route/app.php`）**不会在 HTTP 请求中加载**，其中定义的路由名（如 `files.download`）无法用 `url()` 生成。

插件在 `RouteLoaded` 事件中自动补加载全局 `route/*.php`（用 `include_once`，与框架已加载的场景不冲突），使全局路由名在请求中可用。`php think route:list` 能看到但请求中用不到的场景即由此修复。

### 多应用下 URL 生成注意

- 跨应用链接：`url('admin.login.index')` 由插件自动转 `/admin/login/index`，无影响。
- 全局路由名：`url('files.download', [...])` 由插件去掉框架强加的当前应用前缀，生成 `/files/...`。
- 以上均需先按上文在应用 `common.php` 覆盖 `url()`。

## 测试 / 调试

查看注解生成的路由：

```bash
php think route:list
```

路由有改动后可清缓存：

```bash
php think clear
```

## 限制

- 仅扫描 `app/` 下映射到基础命名空间（默认 `app`）的控制器文件。
- `controllers` 额外目录应位于 `app` 命名空间树内，否则反射出的类可能无法自动加载。
- 注解数组键值必须使用 `=>` 语法。
- 仅扫描公共方法，忽略 `__construct` / `__destruct`，忽略抽象类、接口与 trait。
- 路由有改动后需清缓存（`php think clear`）。