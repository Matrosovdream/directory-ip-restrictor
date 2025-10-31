<?php
/**
 * Plugin Name: Directory IP Restrictor
 * Description: Restrict access to specific folders/pages by username + IP whitelist (with optional child-path restriction).
 * Version:     1.1.0
 * Author:      You
 * Text Domain: directory-ip-restrictor
 */

if ( ! defined('ABSPATH') ) exit;

// Simple PSR-0-ish loader for our few classes
spl_autoload_register(function($class){
    if (strpos($class, 'DirIp') !== 0) return;

    $base = __DIR__;

    $paths = [
        $base . '/actions/' . $class . '.php',
        $base . '/classes/admin/' . $class . '.php',
        $base . '/classes/helpers/' . $class . '.php',
    ];

    foreach ($paths as $p) {
        if (file_exists($p)) { require_once $p; return; }
    }
});

// Register hooks via action classes
if (class_exists('DirIpActionAdmin')) {
    DirIpActionAdmin::register();
}
if (class_exists('DirIpActionRuntime')) {
    DirIpActionRuntime::register();
}
