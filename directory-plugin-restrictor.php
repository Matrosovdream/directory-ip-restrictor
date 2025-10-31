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




/**
 * -----------------------------------------------------------------------------
 * Protects all files under /wp-content/uploads/formidable/
 * - Keeps the same public URLs.
 * - Denies access unless user is logged in and has 'manage_options'.
 * -----------------------------------------------------------------------------
 * Example:
 *   https://passport3.stan-ideas.com/wp-content/uploads/formidable/7/31FE0E69-B9C1-482C-BBB5-2F763275F507.jpeg
 */
final class ProtectedFormidableUploads {

    public static function init(): void {
        add_action('template_redirect', [__CLASS__, 'maybe_intercept_formidable_file']);
    }

    public static function maybe_intercept_formidable_file(): void {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Only intercept if path contains /wp-content/uploads/formidable/
        if (strpos($request_uri, '/wp-content/uploads/formidable/') === false) {
            return;
        }

        // Map request to real path
        $abs_path = ABSPATH . ltrim($request_uri, '/');
        $abs_path = strtok($abs_path, '?'); // remove query params

        if ( ! file_exists($abs_path) ) {
            status_header(404);
            exit('File not found.');
        }

        // Only admins can access
        if ( ! is_user_logged_in() || ! current_user_can('manage_options') ) {
            status_header(403);
            exit('Access denied.');
        }

        self::serve_file($abs_path);
    }

    private static function serve_file(string $path): void {
        $mime = wp_check_filetype($path);
        $type = $mime['type'] ?: 'application/octet-stream';

        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . basename($path) . '"');

        readfile($path);
        exit;
    }
}

ProtectedFormidableUploads::init();
