# cos-api

随机腾讯云 COS 壁纸服务：访问 `/` 返回一张随机壁纸的 HTML 页面，访问 `/api/` 直接 302 跳转到随机壁纸地址。

- 演示：<https://api.lonelyx.cn>
- 运行环境：PHP 8.1+（已在 PHP 8.5 上验证；需要 `curl`、`openssl`、`mbstring`、`simplexml`、`zip` 扩展）、Composer
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

### 加速域名填在哪里

程序生成的图片地址固定为：

```text
https://<COS_DOMAIN>/<folder>/<object>
```

`<folder>` 是 `horizontal_wallpapers` 或 `catgirl_wallpapers`，`<object>` 是 COS 中的对象 Key。
`COS_DOMAIN` 填 **绑定到该存储桶的 CDN 加速域名**，不是 COS API 域名，也不是桶名。

还没有加速域名时，可在 COS 控制台给存储桶绑定“自定义 CDN 加速域名”，或在 CDN 控制台添加域名并将源站设为该桶；按提示完成 CNAME 与 HTTPS 证书配置后，再把该域名填入 `COS_DOMAIN`。

三种写法，优先级从高到低：

1. **环境变量（推荐）**

   ```bash
   COS_DOMAIN=image.lonelyx.cn
   ```

   PHP-FPM 可在 pool 配置中写 `env[COS_DOMAIN] = image.lonelyx.cn`；Docker 用 `-e COS_DOMAIN=...`；宝塔等面板一般在“网站 → 环境变量”中设置。

2. **`key/config.local.php`**（已 gitignore，不会被打包）

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

3. **旧版 `key/Tencent Key.php` 中的 `$domain`**（不推荐，文件位于 Web 目录内）

### 加速域名的写法

| 写法 | 是否推荐 | 说明 |
| --- | --- | --- |
| `image.lonelyx.cn` | ✅ | 裸域名，最清晰 |
| `https://image.lonelyx.cn` | ⚠️ | 能识别，会自动去掉协议；统一写裸域名即可 |
| `image.lonelyx.cn/` | ⚠️ | 能识别，会自动去掉结尾 `/` |
| `cdn.example.com/wallpaper` | ✅ | CDN 配置了路径前缀时使用，最终地址为 `https://cdn.example.com/wallpaper/<folder>/<object>` |
| `image.lonelyx.cn/horizontal_wallpapers` | ❌ | 不要带壁纸目录，程序会自动拼接 |
| `example-1250000000.cos.ap-guangzhou.myqcloud.com` | ⚠️ | 仅当没有 CDN、且桶为公共读时可用；它不是“加速域” |
| `https://image.lonelyx.cn/abc.jpg` | ❌ | 不要带具体对象路径 |

注意：

- 加速域名必须开启 HTTPS。程序固定输出 `https://`，HTTP 域名会导致混合内容被浏览器拦截。
- 不设置 `COS_DOMAIN` 时回落到示例域名 `image.lonelyx.cn`；部署自己的站点务必修改，否则图片会指向示例站。
- 如果 CDN 开启“忽略查询参数”，`Accept: image/webp` 触发的 `?imageMogr2/format/webp` 会失效；需要保留查询串才能按需转 WebP。
- 修改环境变量后重启 PHP-FPM 或容器；`config.local.php` 每次请求都会读取，改完即时生效。

### 完整环境变量

| 变量 | 必填 | 说明 |
| --- | --- | --- |
| `COS_BUCKET` | 是 | 存储桶，例如 `example-1250000000` |
| `COS_REGION` | 是 | 地域，例如 `ap-guangzhou` |
| `COS_SECRET_ID` | 是 | 腾讯云 SecretId |
| `COS_SECRET_KEY` | 是 | 腾讯云 SecretKey |
| `COS_DOMAIN` | 否 | CDN 加速域名（裸域名，需 HTTPS），默认 `image.lonelyx.cn`，可带端口/路径前缀 |
| `COS_CACHE_TTL` | 否 | 对象列表缓存秒数，默认 `300`，范围 `0`–`86400` |
| `COS_CACHE_DIR` | 否 | 缓存目录，默认系统临时目录下的应用私有目录 |

优先级：环境变量 > `key/config.local.php`（已 gitignore）> `key/Tencent Key.php`。
注意 `COS_CACHE_TTL` 只从环境变量读取，`config.local.php` 中不支持 `ttl` 键。

## 使用

- `GET /`：HTML 页面，`<img>` 指向随机壁纸
- `GET /api/`：302 到随机壁纸
- `?type=horizontal`（默认）或 `?type=vertical`；其他值一律回落到 `horizontal`
- 请求头带 `Accept: image/webp` 时，URL 追加 `?imageMogr2/format/webp`

## 部署

1. 把 `cos-api.zip` 解压到站点根目录（或上传 `index.php`、`api/`、`lib/`、`key/`、`styles.css`、`favicon.ico`、`vendor/`）。
2. 配置环境变量：至少 `COS_BUCKET`、`COS_REGION`、`COS_SECRET_ID`、`COS_SECRET_KEY`，并把 `COS_DOMAIN` 改为自己的加速域名。
3. 禁止 Web 访问 `key/`、`lib/`、`tests/`。Apache 已附带 `.htaccess`；Nginx 示例：

   ```nginx
   location ~ ^/(key|lib|tests)/ {
       deny all;
   }
   ```

4. 重启 PHP-FPM 或容器，使环境变量生效。若通过 shell 注入变量，确认 PHP-FPM 未开启 `clear_env`，或改用 pool 中的 `env[COS_DOMAIN] = ...`。
5. 验证输出：

   ```bash
   curl -s https://your-site/ | grep -o '<img[^>]*>'
   curl -sI 'https://your-site/api/?type=vertical' | grep -i '^location:'
   ```

   两条命令中的主机名都应为 `COS_DOMAIN` 的值。

6. 首次请求会列举 COS 对象并写入缓存，默认缓存 300 秒；后续请求不再访问 COS。

页面返回 503 时，查看 PHP 错误日志中的 `cos-api:` 行；页面本身不会输出凭据、堆栈或路径。

## 开发与测试

```bash
composer install
composer check   # php -l + 单元测试
```

单元测试不访问网络，也不需要真实凭据。

代码只使用 PHP 8.1+ 的稳定语法，并对可空参数显式声明 `?type`，不含 PHP 8.4/8.5 已弃用的隐式 nullable、`md5` 签名等写法；CI 矩阵覆盖 PHP 8.3、8.4、8.5，其中 8.5 为当前最新稳定版。

## 构建

CI 工作流（`.github/workflows/php.yml`）在 `dev`/`lonely`/`main` 分支推送或手动触发时，对 PHP 8.3/8.4/8.5 分别执行：

1. `composer install --no-dev --prefer-dist`
2. `composer lint` + `composer test`
3. `composer audit`
4. PHP 8.5 任务执行 `composer build`，打包 `index.php api lib key styles.css favicon.ico vendor` 为 `cos-api.zip`
5. PHP 8.5 任务上传 artifact（`actions/upload-artifact@v4`）

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
