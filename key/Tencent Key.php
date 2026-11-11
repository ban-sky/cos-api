<?php

/**
 * COS credentials template.
 *
 * Preferred: set environment variables on the web server instead of filling
 * this file in, so secrets never live under the document root:
 *
 *   COS_BUCKET, COS_REGION, COS_SECRET_ID, COS_SECRET_KEY
 *   COS_DOMAIN (optional CDN domain, default image.lonelyx.cn)
 *   COS_CACHE_TTL (optional object-list cache seconds, default 300)
 *
 * Environment variables take precedence over the values below. Alternatively
 * create key/config.local.php (git-ignored) returning the same keys as an
 * array, e.g. ['bucket' => '...', 'region' => '...'].
 *
 * Never commit real credentials. This file is only a template.
 */

// 储存桶名称，例如 example-1250000000
$bucket = '';
// 地区，例如 ap-guangzhou
$region = '';
// 前往 https://console.cloud.tencent.com/cam/capi 获取
$secretId = '';
$secretKey = '';
