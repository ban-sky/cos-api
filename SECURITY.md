# 安全说明

本文记录本次改造的安全审计范围、已修复问题、仍依赖部署环境的风险，以及复核方法。项目面向公网提供随机壁纸，本身不处理用户登录、支付或写入操作。

## 审计范围

- `index.php`、`api/index.php`
- `key/Tencent Key.php`、`key/config.local.php` 的加载逻辑
- `composer.json` / `composer.lock` 依赖
- `.github/workflows/php.yml`
- 1Panel + Nginx 的部署方式

测试环境：PHP 8.5.12（cli + 内置 Web Server）；代码按 PHP 8.1+ 语法编写，CI 覆盖 8.1–8.5。

## 已修复

| 编号 | 问题 | 处理 |
| --- | --- | --- |
| 1 | COS 返回的 `Key` 直接拼入 `<img src>`，可形成 XSS | 每段 `rawurlencode`，输出时 `htmlspecialchars(ENT_QUOTES)` |
| 2 | `api/index.php` 在 `header('Location')` 之前输出 HTML，重定向失效；URL 可被构造 | 先计算 URL 再 302；域名格式校验；不再输出 HTML |
| 3 | `display_errors` 开启时泄露路径、堆栈、配置 | 关闭 `display_errors`，异常只写 `error_log`，访客收到通用 503 |
| 4 | COS 返回空 `Contents` 时 `array_rand()` 报错，暴露内部信息 | 过滤非法对象，空列表抛统一异常并返回 503 |
| 5 | `?type[]=x` 触发 `TypeError` | 只接受字符串，数组等输入回退到横屏 |
| 6 | 密钥文件放在 Web 根目录，且被 Git 跟踪 | 保留原路径兼容，但新增 `key/.htaccess`、`.gitignore`；文档要求 Nginx deny；推荐 `config.local.php` |
| 7 | 依赖中包含完全未使用的 `tencentcloud/tencentcloud-sdk-php` | 移除，只保留 `qcloud/cos-sdk-v5`，并生成 `composer.lock` |
| 8 | 无实际校验作用的 `md5(timestamp + nonce + secretKey)` 隐藏字段，容易被误认为签名 | 删除 |
| 9 | 首页引用 `../styles.css`，路径错误 | 改为 `styles.css`，图片样式移到 `.wallpaper` 类 |
| 10 | 响应缺少内容类型保护、Referrer 策略 | 增加 `X-Content-Type-Options: nosniff`、`Referrer-Policy: strict-origin-when-cross-origin`、CSP |
| 11 | qcloud SDK 默认 `scheme => http`，COS 请求可能走明文 | 显式设置 `scheme => https` |

### 关于 CSP 和 iframe

没有设置 `frame-ancestors 'none'` / `X-Frame-Options: DENY`，是为了不破坏原有页面被 iframe 嵌入的用法。页面没有登录、支付、表单等敏感操作，点击劫持的实际影响很低；如果部署场景不需要被嵌入，可以在 Nginx 或代码中自行加上：

```nginx
add_header X-Frame-Options "DENY" always;
```

## 仍依赖部署环境的风险

1. **密钥目录可访问性**：代码保留 `key/` 目录兼容旧配置。Nginx 不读取 `.htaccess`，必须在站点配置中 deny `/key/`、`/vendor/`。最彻底的做法是把配置移出 Web 根目录，但这会改变原版目录结构，本次未做。
2. **限流与费用**：接口每次请求都会调用 COS `ListObjects`。公网暴露时应配置 1Panel WAF 或 Nginx `limit_req`，否则可能被刷产生 COS 请求费用。
3. **子账号权限**：必须使用 CAM 子账号，只授予目标存储桶的列举和读取权限；不要使用主账号密钥。
4. **HTTPS**：程序生成 `https://` 图片地址；站点和 CDN 都需要有效证书。
5. **PHP 版本**：生产环境应使用 8.5.12 或更高安全版本；8.5.8 及更早版本存在 CVE-2026-109618 / CVE-2026-109619。
6. **依赖更新**：`composer audit` 只反映当前锁文件；应定期更新并重新审计。
7. **错误日志**：日志可能包含 COS 返回的桶名等信息，日志目录不应公开访问。

## 复核命令

```bash
# 语法
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

# 依赖漏洞
composer audit

# 检查是否误提交真实密钥（示例，按需调整）
grep -RInE 'AKID[A-Za-z0-9]{10,}|secretKey[[:space:]]*=' \
  --exclude-dir=vendor --exclude-dir=.git .

# 检查危险函数
grep -RInE '\b(eval|exec|system|passthru|shell_exec|unserialize)\s*\(' \
  --exclude-dir=vendor --exclude-dir=.git .
```

本次检查结果：语法通过；`composer audit` 无已知漏洞；未发现真实密钥；未使用上述危险函数。

## 报告问题

如果发现安全问题，请不要在公开 Issue 中粘贴密钥或完整利用代码，先通过仓库维护者提供的私下渠道联系。
