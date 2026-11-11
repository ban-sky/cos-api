# cos-api

随机腾讯云 COS 壁纸服务：访问 `/` 返回一张随机壁纸的 HTML 页面，访问 `/api/` 直接 302 跳转到随机壁纸地址。

- 演示：<https://api.lonelyx.cn>
- 运行环境：PHP 8.1+（需要 `curl`、`openssl` 扩展）、Composer
- 依赖：`qcloud/cos-sdk-v5`（已移除未使用的 `tencentcloud/tencentcloud-sdk-php`）

## 目录结构

```
index.php               HTML 页面入口
api/index.php           302 跳转接口
lib/bootstrap.php       自动加载、错误处理等公共启动逻辑
lib/wallpaper.php       类型白名单、对象列表、缓存、URL 构造
key/Tencent Key.php     凭据模板（可留空，改用环境变量）
tests/run.php           无依赖单元测试
tools/build.php         生成 cos-api.zip 的构建脚本
```

## 配置

推荐使用环境变量，凭据不必写进 Web 目录：

| 变量 | 必填 | 说明 |
| --- | --- | --- |
| `COS_BUCKET` | 是 | 存储桶，例如 `example-1250000000` |
| `COS_REGION` | 是 | 地域，例如 `ap-guangzhou` |
| `COS_SECRET_ID` | 是 | 腾讯云 SecretId |
| `COS_SECRET_KEY` | 是 | 腾讯云 SecretKey |
| `COS_DOMAIN` | 否 | CDN/自定义域名，默认 `image.lonelyx.cn`，可带路径前缀 |
| `COS_CACHE_TTL` | 否 | 对象列表缓存秒数，默认 `300`，范围 `0`–`86400` |
| `COS_CACHE_DIR` | 否 | 缓存目录，默认系统临时目录下的应用私有目录 |

优先级：环境变量 > `key/config.local.php`（已 gitignore）> `key/Tencent Key.php`。

也可以在 `key/config.local.php` 中返回数组：

```php
<?php
return [
    'bucket' => 'example-1250000000',
    'region' => 'ap-guangzhou',
    'secretId' => '...',
    'secretKey' => '...',
    'domain' => 'image.lonelyx.cn',
];
```

## 使用

- `GET /`：HTML 页面，`<img>` 指向随机壁纸
- `GET /api/`：302 到随机壁纸
- `?type=horizontal`（默认）或 `?type=vertical`；其他值一律回落到 `horizontal`
- 请求头带 `Accept: image/webp` 时，URL 追加 `?imageMogr2/format/webp`

## 开发与测试

```bash
composer install
composer check   # php -l + 单元测试
```

单元测试不访问网络，也不需要真实凭据。

## 构建

CI 工作流（`.github/workflows/php.yml`）在 `dev`/`lonely`/`main` 分支推送或手动触发时：

1. `composer install --no-dev --prefer-dist`
2. `composer lint` + `composer test`
3. `composer build` 打包 `index.php api lib key styles.css favicon.ico vendor` 为 `cos-api.zip`
4. 上传 artifact（`actions/upload-artifact@v4`）

本地等价的构建命令：

```bash
composer install --no-dev --prefer-dist --classmap-authoritative
composer build
```

`tools/build.php` 使用 PHP 的 `zip` 扩展生成压缩包，Linux/macOS/Windows 行为一致；
部署时把 `cos-api.zip` 解压到站点根目录即可，`key/config.local.php` 不会被打包。

## 安全

审计结论与残余风险见 [SECURITY.md](SECURITY.md)。要点：

- 凭据优先用环境变量；`key/`、`lib/`、`tests/` 自带 Apache 拒绝访问规则，Nginx 需自行加 `deny all`。
- 壁纸类型走白名单，对象 Key 逐段百分号编码后再输出/跳转，避免 XSS 与响应头注入。
- 错误统一返回 503 纯文本，不输出堆栈与路径。
- 对象列表默认缓存 5 分钟；COS 故障时回退到过期缓存。

## 相对旧版的主要改动

- 合并 `index.php` 与 `api/index.php` 的重复逻辑到 `lib/wallpaper.php`。
- 修复 `api/index.php` 先输出 HTML 再 `header('Location: ...')` 导致的重定向失败。
- 修复空桶/Listing 失败时 `array_rand()` 抛 `ValueError`、`['Contents']` 未定义等问题。
- 增加分页列举、缓存与过期缓存回退；按 1000 条/页最多翻 20 页。
- 增加类型白名单、URL 编码、CSP/`nosniff`、错误信息脱敏。
- 移除未使用的 `tencentcloud/tencentcloud-sdk-php`，vendor 从约百 MB 降到约 5 MB。
- 提交 `composer.lock`，构建可复现。
