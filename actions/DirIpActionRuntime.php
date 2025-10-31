<?php
if ( ! defined('ABSPATH') ) exit;

final class DirIpActionRuntime {
    public static function register(): void {
        // Run very early to avoid unnecessary processing
        add_action('template_redirect', [__CLASS__, 'maybe_block_request'], 0);
    }

    public static function maybe_block_request(): void {
        // Exception for WordPress admin and administrators
        if ( is_admin() || current_user_can('manage_options') ) {
            return;
        }

        $settings = DirIpHelpers::get_settings();
        $rules    = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
        if (empty($rules)) return;

        $req_path = DirIpHelpers::current_request_path();
        if ($req_path === '') return;

        foreach ($rules as $rule) {
            // Default to active if key is missing (for older saves)
            $is_active = array_key_exists('active', $rule) ? (bool)$rule['active'] : true;
            if (!$is_active) continue;

            $path = DirIpHelpers::normalize_path((string)($rule['path'] ?? ''));
            if ($path === '') continue;

            $is_child = !empty($rule['restrict_children']);

            if ( DirIpHelpers::path_matches($req_path, $path, (bool)$is_child) ) {
                // Allow if user has any allowed role OR matches extra IP groups
                if ( DirIpHelpers::is_allowed_for_current_user($rule) ) {
                    return; // allowed
                }

                // Block
                status_header(403);
                nocache_headers();
                wp_die(
                    esc_html__('Access forbidden', 'directory-ip-restrictor'),
                    esc_html__('Forbidden', 'directory-ip-restrictor'),
                    ['response' => 403]
                );
            }
        }
    }
}
