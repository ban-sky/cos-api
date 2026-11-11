# 安全审计

- 审计对象：`lonely-starry/cos-api`（基线提交 `1551792`）及本次重构后的代码
- 审计时间：2026-11-11
- 方法：手工代码审计、依赖梳理、`composer audit`、本地动态验证
- 结论：未发现可直接导致远程代码执行或凭据泄露的高危漏洞；已修复若干中低风险问题。残余风险主要来自部署方式（Web 服务器配置、密钥权限、限流）。

## 已修复

### 1. 对象 Key 导致的 XSS / 属性逃逸（中）

原 `index.php` 直接把 `https://域名/对象Key` 拼进 `src="..."`，未做 HTML 转义。对象 Key 可由拥有桶写权限的账号控制，若包含 `"` 即可闭合属性并注入任意 HTML/JS。

修复：所有输出经 `htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`；URL 构造时对 Key 按 `/` 分段 `rawurlencode`，从源头消除引号、空格、尖括号。

### 2. 响应头注入（中）

原 `api/index.php` 执行 `header('Location: ' . $wallpaperUrl)`，URL 中的 Key 未编码。虽然现代 PHP 会拒绝包含 CR/LF 的响应头，但把不可信数据直接送入响应头仍是缺陷。

修复：`wallpaper_url()` 对域名做字符白名单校验（`^[A-Za-z0-9.\-]+(?::\d+)?(?:/[...])?$`）、固定 `https` 协议、Key 逐段百分号编码，再交给 `header(..., true, 302)`。

### 3. 凭据放在 Web 根目录且被 Git 跟踪（高，架构性）

`key/Tencent Key.php` 位于站点目录内。按原注释填入真实 `secretKey` 后，一旦服务器把 `.php` 当静态文件下载、产生 `.bak`/`.swp` 备份，或 `AllowOverride` 未开启导致防护失效，凭据即泄露。仓库也没有 `.gitignore`，误提交风险高。

修复：

- 配置优先级改为 `环境变量 > key/config.local.php（gitignore）> key/Tencent Key.php`，生产环境可完全不落盘。
- `key/`、`lib/`、`tests/` 添加 Apache `Require all denied`（含 2.2 兼容写法）。
- 仓库中的 `key/Tencent Key.php` 保持空模板并加警示注释。
- README 给出 Nginx 的 `deny all` 配置要求。

残余：Nginx 或未开启 `AllowOverride` 的 Apache 不会读取 `.htaccess`，部署方必须自行加规则；更彻底的做法是把 `key/` 移出 document root。

### 4. 错误信息泄露（中）

原代码对 `listObjects()` 失败、空桶、`Contents` 缺失均无处理，异常/警告会把堆栈、绝对路径、Bucket 名输出给客户端。

修复：两个入口统一 `try/catch (Throwable)`，`display_errors=0`、`log_errors=1`，失败只返回固定文案与 HTTP 503；缓存/列表异常不会把 SDK 原始错误写入响应。

### 5. 无意义的 MD5 “签名”（低）

原代码计算并输出 `md5($timestamp . $nonce . $secretKey)`，但仓库内没有任何代码校验它，客户端也无法验证；它既不是有效的鉴权，又给外部一个以密钥为输入的摘要 oracle，并固化了 MD5 的使用。

修复：删除该逻辑及两个隐藏字段（确认无消费者）。若未来需要防刷，应在网关做限流/签名校验，而不是把 keyed digest 交给客户端。

### 6. 依赖面过大（中）

`composer.json` 同时引入 `tencentcloud/tencentcloud-sdk-php`（全产品 SDK，体量大、传递依赖多），但代码只使用 `qcloud/cos-sdk-v5` 的 `Qcloud\Cos\Client`。

修复：移除未使用依赖。仅保留 `qcloud/cos-sdk-v5` 及其传递依赖后，vendor 为 5.2 MB；`composer audit` 报告 0 条已知漏洞；提交 `composer.lock` 固定版本。

### 7. 构建与 CI 供应链（中）

原工作流没有 lock 文件、使用已弃用的 `actions/checkout@v2` 与 `actions/upload-artifact@v3.1.3`、没有最小化 `permissions`，且不做 lint/测试就打包。

修复：提交 lock；改用 `actions/checkout@v4`、`actions/upload-artifact@v4`、`permissions: contents: read`；`composer install --no-dev --prefer-dist`；打包前执行 `composer lint` 与 `composer test`；`composer audit` 无告警。

### 8. 请求放大 / 资源耗尽（中）

原实现每个请求都调用一次 `listObjects`（最多 1000 个对象），流量突增时会放大 COS 调用、触发限频并产生费用。

修复：对象列表落盘缓存（默认 300 秒，可用 `COS_CACHE_TTL` 调整）；分页最多 20 页、每页 1000 条；COS 故障时回退到过期缓存（stale-while-error）。残余：没有应用层限流，建议在 CDN/WAF 层限制单 IP 频率。

### 9. 缓存文件安全（低）

缓存内容为对象 Key（非机密），但落在共享临时目录可能被其他本地用户读取或篡改，进而改变跳转目标。

修复：应用私有子目录（`0700`，目录名含部署路径哈希）、临时文件 `0600`、`rename` 原子替换、读取时校验 JSON 结构与元素类型。篡改最多把请求指向同一域名下的其他对象，无法越出域名或注入协议。

### 10. 点击劫持 / MIME 嗅探（低）

修复：HTML 响应加 `Content-Security-Policy: default-src 'none'; img-src https:; style-src 'self'; frame-ancestors 'none'`、`X-Content-Type-Options: nosniff`、`Referrer-Policy: no-referrer`；跳转响应加 `nosniff`。页面样式移到 `styles.css`，不使用内联样式，CSP 无需 `unsafe-inline`。

## 功能缺陷（顺带修复）

- `api/index.php` 在 `header('Location: ...')` 之前输出完整 HTML，导致 “headers already sent”，302 实际失效。现在入口文件不产生任何输出。
- 空桶或列举失败时 `array_rand([])` 在 PHP 8 抛 `ValueError`；`listObjects(...)['Contents']` 在无对象时触发未定义键警告。现在空列表返回 `null` 并转为 503。
- `require '../vendor/autoload.php'` 依赖当前工作目录，改用 `__DIR__` 绝对路径。
- 两个入口的 `switch` 重复，合并为单一白名单映射。

## 残余风险与建议

1. **凭据最小权限**：为本站单独创建子账号，仅授予 `GetBucket`（ListBucket）+ `GetObject`，并通过策略限制到两个前缀；定期轮换密钥。
2. **密钥位置**：生产环境优先用环境变量或 systemd/容器 secret；否则把 `key/` 移出 document root，并在 Nginx 添加 `location ^~ /key/ { deny all; }`。
3. **限流与防盗链**：接口无频率限制，建议 CDN/WAF 按 IP 限流；CDN 侧开启 Referer 防盗链或签名 URL，避免流量盗用。
4. **缓存时效**：新增壁纸最多延迟 `COS_CACHE_TTL` 秒可见；需要即时生效时设为 `0`（每次回源）。
5. **依赖更新**：定期执行 `composer audit` 与 `composer update --dry-run`；SDK/Guzzle 的安全通告需及时跟进。
6. **PHP 运行环境**：关闭 `expose_php`，`display_errors=Off`，仅开放必要扩展；建议 PHP 8.2/8.3 的最新补丁版本。
7. **桶权限**：若桶为公共读，对象 Key 本身不算机密；不要开启公共写，避免他人上传内容后经本站输出（现已做转义与编码，但仍应禁止公共写）。

## 验证记录

- `composer audit`：`No security vulnerability advisories found.`
- 单元测试：21 项全部通过，覆盖类型白名单、URL 编码、缓存读写/过期/回退、分页、配置优先级、空列表与异常路径。
- 动态验证：
  - 未配置凭据时 `/` 与 `/api/` 返回 503 纯文本，无堆栈/路径。
  - 预置缓存后 `/api/?type=vertical` 返回 302，`Location` 为编码后的 HTTPS 地址。
  - `?type=../../etc/passwd` 回落到 `horizontal`，不会拼接进 Key。
  - 带 `Accept: image/webp` 时 `Location` 追加 `?imageMogr2/format/webp`。
