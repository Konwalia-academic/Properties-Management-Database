<?php
/**
 * PMD API 入口（web 根下的 /api.php → app/api.php）
 * 兼容 /api.php?action=xxx 的调用方式；/api/* 路径则由 index.php 路由
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/api.php';
