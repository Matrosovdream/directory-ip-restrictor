<?php
if ( ! defined('ABSPATH') ) exit;

final class DirIpActionAdmin {
    public static function register(): void {
        add_action('admin_menu',  [DirIpAdmin::class, 'add_admin_page']);
        add_action('admin_init',  [DirIpAdmin::class, 'register_settings']);
    }
}
