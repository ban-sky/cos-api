<?php
// 存储桶名称，例如 example-1250000000
$bucket = '';

// 存储桶地域，例如 ap-guangzhou
$region = '';

// 前往 https://console.cloud.tencent.com/cam/capi 创建子账号密钥
$secretId = '';
$secretKey = '';

// CDN / 自定义域名，只写域名（可带路径前缀），不要写 https:// 和结尾斜杠
// 不填写时使用默认值 image.lonelyx.cn，兼容旧版配置文件
$domain = 'image.lonelyx.cn';

// 安全提示：不要把真实密钥提交到 Git。
// 推荐把真实配置写到 key/config.local.php（该文件已被 .gitignore 忽略），
// 本文件保持为空模板即可。
