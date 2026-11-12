# cos-api

一个从腾讯云 COS 随机取一张壁纸的极简 PHP 接口：

- `/`：返回一个包含随机壁纸的 HTML 页面。
- `/api/?type=horizontal`：302 跳转到横屏壁纸。
- `/api/?type=vertical`：302 跳转到竖屏壁纸。

项目不依赖数据库，不需要写缓存目录，保留原来的目录结构和配置方式。本次改造只做 PHP 8.5 适配、安全加固和文档重写，不改变现有 URL、参数和配置文件名。

## 目录结构

```text
cos-api/
├── api/
│   └── index.php              # 302 接口
├── key/
│   ├── Tencent Key.php        # 配置模板（变量写法，兼容旧版）
│   ├── config.local.php       # 可选：真实密钥（已被 .gitignore 忽略）
│   └── .htaccess              # Apache 下禁止直接访问 key/
├── index.php                  # 随机壁纸页面
├── styles.css
├── favicon.ico
├── composer.json
├── composer.lock
├── SECURITY.md                # 安全审计与部署注意事项
├── vendor/                    # composer install 生成，不提交 Git
└── README.md
```

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.1 – 8.5，生产建议 **8.5.12 或更高** |
| PHP 扩展 | `curl`、`json`、`mbstring`、`openssl`、`simplexml` |
| Composer | 2.x（仅安装依赖时需要） |
| 腾讯云 | 一个 COS 存储桶，以及只拥有该桶读取权限的子账号密钥 |
| CDN | 可选。没有 CDN 时可填 COS 默认域名 |

> PHP 8.5.12 修复了两个可未授权利用的远程代码执行漏洞（CVE-2026-109618、CVE-2026-109619）。1Panel 已提供 `1panel/php:8.5.12` 等修复镜像，生产环境不要继续使用 8.5.8 及更早版本。参考：[1Panel 运行环境安全升级说明](https://docs.fit2cloud.com/1panel/faq/runtime-security-upgrade/)。

## 配置

配置按以下顺序加载，后者覆盖前者：

1. `key/Tencent Key.php`（原版变量写法，保持兼容）
2. `key/config.local.php`（可选，推荐放真实密钥）

配置文件只应设置变量或返回数组，不要输出内容；建议不要写 `?>` 结束标记，避免结束标记后的空白被输出。

### 方式一：继续使用原版配置文件

编辑 `key/Tencent Key.php`：

```php
<?php
$bucket = 'example-1250000000';   // 存储桶名称
$region = 'ap-guangzhou';         // 存储桶地域
$secretId = 'AKID...';            // 子账号 SecretId
$secretKey = '...';               // 子账号 SecretKey

// 可选；不填则使用 image.lonelyx.cn
$domain = 'img.example.com';
```

### 方式二：使用 config.local.php（推荐）

新建 `key/config.local.php`：

```php
<?php
return [
    'bucket' => 'example-1250000000',
    'region' => 'ap-guangzhou',
    'secretId' => 'AKID...',
    'secretKey' => '...',
    'domain' => 'img.example.com',
];
```

这个文件已在 `.gitignore` 中，不会被误提交。`Tencent Key.php` 可以保持空模板。

### 加速域名怎么填

`domain` 只填 **域名（可带路径前缀）**，不要带协议和结尾斜杠：

| 写法 | 结果 |
| --- | --- |
| `img.example.com` | `https://img.example.com/horizontal_wallpapers/xxx.jpg` |
| `img.example.com/wallpaper` | `https://img.example.com/wallpaper/horizontal_wallpapers/xxx.jpg` |
| `https://img.example.com/` | 自动去掉 `https://` 和结尾 `/`，等价于第一种 |
| `img.example.com:8443` | 使用自定义端口 |
| 空字符串或不填 | 回退到 `image.lonelyx.cn` |

程序始终生成 `https://` 链接。CDN 回源地址应指向 COS 存储桶，并允许访问 `horizontal_wallpapers/`、`catgirl_wallpapers/` 两个目录。

## 在 1Panel 上部署

以下步骤适用于 1Panel v2。不同小版本的菜单名称可能略有差异，以面板实际显示为准。

### 1. 安装 PHP 8.5 运行环境

1. 进入 **应用商店 → 运行环境**。
2. 先点 **更新远程应用**，确保能获取最新版本。
3. 安装 PHP，版本选择 **8.5.12**（或更高的 8.5.x 安全版本）。
4. 在运行环境的 **配置 / 扩展** 中确认已启用：
   `curl`、`mbstring`、`simplexml`、`zip`、`fileinfo`、`openssl`。
5. 安装完成后，在运行环境终端执行 `php -v` 和 `php -m` 确认版本与扩展。

> `zip` 主要供 Composer 解压依赖使用；程序运行时只需要 `curl`、`json`、`mbstring`、`openssl`、`simplexml`。

### 2. 创建网站

1. 进入 **网站 → 创建网站**。
2. 类型选择 **运行环境**，运行环境选择刚安装的 PHP 8.5。
3. 填写域名，例如 `api.example.com`。
4. 网站目录使用 1Panel 默认目录，例如：
   `/www/sites/api.example.com/index`
5. 不需要创建数据库，也不需要 FTP。
6. 创建后，如果目录里出现 1Panel 默认的 `index.html`，请删除它，避免它优先于 `index.php`。

### 3. 上传代码

将本项目文件上传到网站根目录，最终结构应为：

```text
/www/sites/api.example.com/index/
├── index.php
├── api/index.php
├── key/Tencent Key.php
├── styles.css
├── favicon.ico
└── vendor/
```

可以用 1Panel 文件管理器上传压缩包后解压，也可以在服务器上克隆到临时目录再复制：

```bash
git clone <你的仓库地址> /tmp/cos-api
cp -a /tmp/cos-api/. /www/sites/api.example.com/index/
```

如果使用 Git，注意不要把线上 `key/config.local.php` 覆盖掉。

### 4. 安装 Composer 依赖

推荐在本地安装后上传 `vendor/`，这样不依赖服务器上的 Composer：

```bash
composer install --no-dev --optimize-autoloader
```

然后把整个 `vendor/` 目录上传到网站根目录。

如果要在服务器上安装，先进入 1Panel 的 PHP 运行环境终端（或在面板 **容器** 中找到 PHP 容器）：

```bash
docker ps --format '{{.Names}}' | grep -i php
docker exec -it <php容器名> sh

cd /www/sites/api.example.com/index
php -v
curl -sS https://getcomposer.org/installer | php
php composer.phar install --no-dev --optimize-autoloader
```

安装完成后确认：

```bash
php -r "require 'vendor/autoload.php'; echo 'autoload ok', PHP_EOL;"
```

### 5. 写入配置并设置权限

在 1Panel 文件管理器中编辑 `key/Tencent Key.php`，或新建 `key/config.local.php`（推荐，见上文）。

然后设置权限。1Panel 的 PHP 容器通常以 `www-data` 运行，先确认实际用户：

```bash
docker exec -it <php容器名> id
```

如果输出是 `uid=33(www-data)`，执行：

```bash
cd /www/sites/api.example.com/index

# 目录 755，文件 644；PHP 只需要读权限
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

# 配置文件只给 PHP 用户读，避免其他用户读取
chown -R www-data:www-data .
chmod 640 "key/Tencent Key.php"
chmod 640 key/config.local.php 2>/dev/null || true

# 不要让 PHP 进程写 key/ 目录
chmod 550 key
```

如果容器用户不是 `www-data`，把上面的用户和组换成 `id` 命令显示的用户即可。不要使用 `chmod 777`。

### 6. 配置 Nginx，禁止访问 key/ 和 vendor/

1Panel 使用 OpenResty/Nginx。进入 **网站 → 对应站点 → 配置文件**，在 `server { ... }` 内加入：

```nginx
# 配置文件、依赖目录不允许直接访问
location ^~ /key/ {
    deny all;
    return 403;
}

location ^~ /vendor/ {
    deny all;
    return 403;
}
```

保存后重载 Nginx。`^~` 的优先级高于后面的 PHP 正则 location，可以确保 `/key/xxx.php` 不会被当作 PHP 执行。

> Apache 环境已经自带 `key/.htaccess`；Nginx 不读取 `.htaccess`，所以必须加上面的配置。

### 7. 开启 HTTPS

进入 **网站 → 对应站点 → HTTPS**：

1. 申请 Let's Encrypt 证书，或上传已有证书。
2. 开启 **强制 HTTPS**。
3. 保存后访问 `https://api.example.com/` 验证。

CDN 域名也应在腾讯云 CDN 控制台配置 HTTPS 证书；程序生成的是 `https://` 图片地址。

### 8. 验证

```bash
# 页面应返回 200，并包含 <img class="wallpaper"
curl -sS -D - https://api.example.com/ -o /tmp/page.html
grep -o 'src="[^"]*"' /tmp/page.html

# 接口应返回 302，Location 指向图片
curl -sS -D - 'https://api.example.com/api/?type=vertical' -o /dev/null

# 直接访问配置文件应返回 403
curl -sS -o /dev/null -w '%{http_code}\n' \
  'https://api.example.com/key/Tencent%20Key.php'
```

期望结果：

- `/`：`200 OK`，HTML 中有 `<img class="wallpaper" ...>`。
- `/api/?type=vertical`：`302 Found`，`Location: https://<加速域名>/catgirl_wallpapers/...`。
- `/key/...`：`403 Forbidden`。
- 浏览器 `Accept: image/webp` 时，图片 URL 末尾带 `?imageMogr2/format/webp`。

## 接口说明

| 路径 | 说明 |
| --- | --- |
| `/` | 随机横屏壁纸 HTML 页面 |
| `/?type=vertical` | 随机竖屏壁纸 HTML 页面 |
| `/api/?type=horizontal` | 302 到随机横屏壁纸 |
| `/api/?type=vertical` | 302 到随机竖屏壁纸 |

`type` 只接受 `horizontal`、`vertical`；其他值（包括数组）按横屏处理。文件夹对应关系：

- `horizontal` → `horizontal_wallpapers/`
- `vertical` → `catgirl_wallpapers/`

## 本次修改内容

### PHP 8.5 适配

- 已在 **PHP 8.5.12** 下完成语法检查、依赖安装和端到端验证。
- 代码只使用 PHP 8.1+ 的稳定语法，没有使用 8.5 已废弃的 API。
- CI 覆盖 PHP 8.1、8.2、8.3、8.4、8.5。

### 安全修复（不改变使用方式）

1. **XSS**：COS 返回的 `Key` 不再直接拼进 `src`，改为逐段 `rawurlencode` + `htmlspecialchars` 输出。
2. **Header 注入 / 重定向失效**：`api/index.php` 不再先输出 HTML 再 `header()`；先计算 URL，再发送 302。域名做协议剥离和格式校验。
3. **错误信息泄露**：关闭 `display_errors`，异常只写日志，访客只看到通用 503。
4. **空结果 / 异常返回**：过滤目录占位对象和非法 `Contents`，空列表不再触发 `array_rand()` 错误。
5. **参数类型攻击**：`?type[]=x` 不再触发 `TypeError`，回退到横屏。
6. **依赖面**：移除完全未使用的 `tencentcloud/tencentcloud-sdk-php`，只保留 `qcloud/cos-sdk-v5`。
7. **密钥保护**：新增 `key/.htaccess`、`.gitignore`，文档要求 Nginx 禁止访问 `/key/`、`/vendor/`；推荐使用 `config.local.php`。
8. **响应头**：增加 `X-Content-Type-Options: nosniff`、`Referrer-Policy: strict-origin-when-cross-origin`、CSP，降低内容嗅探和脚本注入风险。
9. **伪签名清理**：删除无实际校验作用的 `md5(timestamp + nonce + secretKey)` 隐藏字段。
10. **样式路径**：修正首页 `../styles.css` 的错误路径。
11. **COS 传输**：qcloud SDK 默认使用 `http`；现在显式设置 `scheme => https`，避免密钥标识走明文链路。

### 兼容性说明

- 原有 URL、`type` 参数、`key/Tencent Key.php` 变量名、目录结构均保留。
- 旧配置文件即使没有 `$domain` 也能直接使用。
- 新增 `config.local.php` 是可选的，不配置时行为与原版一致。
- 随机选择、WebP 自适应、HTML 页面结构保持不变。
- 主要的行为修正是 `/api/` 现在能正常返回 302（原版先输出 HTML，`header()` 实际不生效）；页面中的隐藏 md5 字段已移除，样式路径已修正。

## 常见问题

### 页面 500 / 503（无法加载）

- `vendor/` 不存在或没有上传完整：程序会返回 503，重新执行 `composer install` 并上传 `vendor/`。
- PHP 扩展缺失：`php -m` 检查 `curl`、`mbstring`、`simplexml`、`openssl`。
- PHP 版本低于 8.1：切换到 8.5.12 运行环境。
- 查看 1Panel 网站日志和 PHP 错误日志。

### 页面 503

程序返回 503 表示配置或 COS 请求失败。查看 PHP 错误日志中的 `cos-api:` 行：

- `COS configuration is incomplete`：`bucket`、`region`、`secretId`、`secretKey` 有空值。
- `key/Tencent Key.php` / `key/config.local.php`：配置文件不可读或有语法错误。
- `Invalid CDN domain`：`domain` 带了协议、引号、空格或非法字符。
- COS 请求异常（如 `cURL error`、`AccessDenied`、`NoSuchBucket`）：密钥无权限、桶名/地域错误、COS 网络不通。

### 直接访问 /key/ 返回 403

这是预期的安全配置。程序内部通过文件系统读取配置，不需要 Web 访问。

### 图片 404

- `domain` 是否填成了 COS 默认域名或已绑定的 CDN 域名。
- CDN 回源是否指向正确的存储桶。
- COS 中是否存在 `horizontal_wallpapers/`、`catgirl_wallpapers/` 目录。
- 密钥是否有 `GetObject` 权限。

### Composer 报扩展缺失

在 1Panel 运行环境中启用对应扩展，或在本机执行：

```bash
composer install --no-dev --optimize-autoloader
```

然后把 `vendor/` 上传到服务器。

### 修改代码后不生效

- 确认上传到了正确的网站目录。
- 1Panel 中重载 PHP 或重启 PHP 容器。
- 如果启用了 OPcache，等待缓存过期或重启 PHP-FPM。

## 安全建议

- 使用腾讯云 CAM **子账号**，只授予该存储桶的列举和读取权限（至少 `ListBucket`、`GetObject`），不要使用主账号密钥。
- `key/` 目录不要放在可公开访问的位置；本项目为保持兼容仍放在 `key/`，因此必须配合 Nginx `deny` 或 Apache `.htaccess`。
- 不要把 `key/config.local.php` 提交到 Git；仓库已忽略该文件。
- 生产环境关闭 `display_errors`，并在 PHP 配置中设置 `expose_php = Off`。
- 为 `/` 和 `/api/` 配置限流（1Panel WAF 或 Nginx `limit_req`），避免接口被刷导致 COS 请求费用。
- 定期执行 `composer update` 和 `composer audit`，及时升级 PHP 安全版本。

## 开发与检查

```bash
# 安装依赖
composer install

# 语法检查
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

# 依赖漏洞检查
composer audit
```

## 许可

未附带开源许可证文件；如需开源，请自行补充 LICENSE。
