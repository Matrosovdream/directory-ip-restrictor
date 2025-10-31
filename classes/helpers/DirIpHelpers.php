<?php
if ( ! defined('ABSPATH') ) exit;

final class DirIpHelpers {
    /**
     * Get plugin settings array from the options table.
     */
    public static function get_settings(): array {
        $opt = get_option(DirIpAdmin::OPTION, ['rules' => []]);
        return is_array($opt) ? $opt : ['rules' => []];
    }

    /**
     * Return the current request path, normalized (leading slash, no trailing slash except '/').
     */
    public static function current_request_path(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') return '';
        $uri = strtok($uri, '?'); // strip query
        return self::normalize_path($uri);
    }

    /**
     * Normalize a URL path (string) to a consistent format.
     */
    public static function normalize_path(string $p): string {
        $p = trim($p);
        if ($p === '') return '';
        if ($p[0] !== '/') $p = '/' . $p;
        if ($p !== '/') $p = rtrim($p, '/');
        return $p;
    }

    /**
     * Path match helper.
     */
    public static function path_matches(string $req, string $rulePath, bool $restrict_children): bool {
        if ($restrict_children) {
            if ($req === $rulePath) return true;
            return (strpos($req, $rulePath . '/') === 0);
        }
        return $req === $rulePath;
    }

    /**
     * Access checker:
     * - If user has any role in allowed_user_groups (for this rule) => ALLOW
     * - Else, usernames are labels only; ALLOW if client's IP matches any IP
     *   listed under "Allow extra groups" user blocks (IPv4/IPv6 exact match; tab/newline-separated).
     */
    public static function is_allowed_for_current_user(array $rule): bool {
        // Role-based allow
        if ( self::user_has_allowed_role($rule) ) {
            return true;
        }

        // IP-based allow (extra groups)
        $ip = self::client_ip();
        if ($ip === '' || ! self::is_valid_ip($ip)) {
            return false;
        }

        $groups = is_array($rule['groups'] ?? null) ? $rule['groups'] : [];
        foreach ($groups as $grp) {
            $users = is_array($grp['users'] ?? null) ? $grp['users'] : [];
            foreach ($users as $u) {
                $ips_text = isset($u['ips']) ? (string) $u['ips'] : '';
                $allowed  = self::parse_ip_block($ips_text); // tab/newline-separated list
                if (empty($allowed)) continue;

                if (in_array($ip, $allowed, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True if current user has any role selected in rule['allowed_user_groups'].
     */
    public static function user_has_allowed_role(array $rule): bool {
        if ( empty($rule['allowed_user_groups']) || !is_array($rule['allowed_user_groups']) ) {
            return false;
        }
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user = wp_get_current_user();
        if ( ! $user instanceof WP_User ) {
            return false;
        }
        $user_roles = is_array($user->roles ?? null) ? $user->roles : [];
        if (empty($user_roles)) {
            return false;
        }

        $allowed = array_map('sanitize_key', $rule['allowed_user_groups']);
        return (bool) array_intersect($allowed, $user_roles);
    }

    /**
     * Validate IPv4 or IPv6.
     */
    public static function is_valid_ip(string $ip): bool {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP);
    }

    /**
     * Parse textarea with IPs (tab/newline-separated) into a de-duplicated list
     * of valid IPs (IPv4 and IPv6). Empty/invalid lines are ignored.
     */
    public static function parse_ip_block(string $text): array {
        if ($text === '') return [];
        // Normalize CRLF to LF
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Split by TAB or NEWLINE (we intentionally do not split by commas anymore)
        $parts = preg_split('/[\t\n]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) return [];

        $out = [];
        foreach ($parts as $p) {
            $ip = trim($p);
            if ($ip === '') continue;
            if (self::is_valid_ip($ip)) {
                $out[] = $ip;
            }
        }
        // Remove duplicates while preserving order
        return array_values(array_unique($out));
    }

    /**
     * Get client IP (IPv4 or IPv6) using REMOTE_ADDR.
     */
    public static function client_ip(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        return self::is_valid_ip($ip) ? $ip : '';
    }
}
