<?php
/**
 * Plugin Name: Directory IP Restrictor
 * Description: Restrict access to specific folders/pages by username + IP whitelist (with child path option). Skips wp-admin and users with manage_options.
 * Version:     1.0.0
 * Author:      You
 * Text Domain: directory-ip-restrictor
 */

if ( ! defined('ABSPATH') ) exit;

final class DIR_IPR_Plugin {
    const OPTION = 'dir_ipr_settings';

    public static function bootstrap(): void {
        add_action('admin_menu', [__CLASS__, 'add_admin_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('template_redirect', [__CLASS__, 'maybe_block_request'], 0); // early
    }

    /**
     * SETTINGS STRUCTURE (stored in option):
     * [
     *   'rules' => [
     *     [
     *       'path' => '/secret',           // folder or page (leading slash recommended)
     *       'restrict_children' => 1,      // 1/0
     *       'groups' => [
     *         [
     *           'name' => 'admin2',
     *           'users' => [
     *             ['username' => 'alice', 'ips' => '1.2.3.4, 5.6.7.8'],
     *             ['username' => 'bob',   'ips' => '10.0.0.1']
     *           ]
     *         ],
     *         // more groups...
     *       ]
     *     ],
     *     // more rules...
     *   ]
     * ]
     */

    /* -------------------- Admin UI -------------------- */

    public static function add_admin_page(): void {
        add_options_page(
            __('Directory IP Restrictor', 'directory-ip-restrictor'),
            __('Directory IP Restrictor', 'directory-ip-restrictor'),
            'manage_options',
            'directory-ip-restrictor',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting(
            'dir_ipr_group',
            self::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
                'default'           => ['rules' => []],
            ]
        );

        add_settings_section(
            'dir_ipr_main',
            __('Access Rules', 'directory-ip-restrictor'),
            function () {
                echo '<p>' . esc_html__('Create rules to restrict specific paths. Only listed (username + IP) pairs are allowed.', 'directory-ip-restrictor') . '</p>';
            },
            'directory-ip-restrictor'
        );

        add_settings_field(
            'dir_ipr_rules',
            __('Rules', 'directory-ip-restrictor'),
            [__CLASS__, 'render_rules_field'],
            'directory-ip-restrictor',
            'dir_ipr_main'
        );
    }

    public static function sanitize_settings($input) {
        $out = ['rules' => []];

        if (!is_array($input) || empty($input['rules']) || !is_array($input['rules'])) {
            return $out;
        }

        foreach ($input['rules'] as $rule) {
            $path = isset($rule['path']) ? trim(wp_unslash($rule['path'])) : '';
            if ($path === '') continue;

            $restrict_children = !empty($rule['restrict_children']) ? 1 : 0;

            $groups_out = [];
            if (!empty($rule['groups']) && is_array($rule['groups'])) {
                foreach ($rule['groups'] as $grp) {
                    $gname = isset($grp['name']) ? sanitize_text_field($grp['name']) : '';
                    $users_out = [];
                    if (!empty($grp['users']) && is_array($grp['users'])) {
                        foreach ($grp['users'] as $u) {
                            $username = isset($u['username']) ? sanitize_user($u['username'], true) : '';
                            $ips_raw  = isset($u['ips']) ? (string)$u['ips'] : '';
                            // keep comma-separated string; we'll parse at runtime
                            $ips = trim(preg_replace('/\s+/', '', $ips_raw));
                            if ($username !== '' && $ips !== '') {
                                $users_out[] = [
                                    'username' => $username,
                                    'ips'      => $ips,
                                ];
                            }
                        }
                    }
                    if ($gname !== '' && !empty($users_out)) {
                        $groups_out[] = [
                            'name'  => $gname,
                            'users' => $users_out,
                        ];
                    }
                }
            }

            $out['rules'][] = [
                'path'              => self::normalize_path($path),
                'restrict_children' => $restrict_children,
                'groups'            => $groups_out,
            ];
        }

        return $out;
    }

    public static function render_page(): void { ?>
        <div class="wrap">
            <h1><?php esc_html_e('Directory IP Restrictor', 'directory-ip-restrictor'); ?></h1>
            <form method="post" action="options.php" id="dir-ipr-form">
                <?php
                settings_fields('dir_ipr_group');
                do_settings_sections('directory-ip-restrictor');
                submit_button();
                ?>
            </form>
        </div>
        <style>
            .dir-ipr-rule, .dir-ipr-group, .dir-ipr-user { border:1px solid #ccd0d4; padding:12px; margin:12px 0; background:#fff; }
            .dir-ipr-flex { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
            .dir-ipr-small { font-size:12px; color:#666; }
            .dir-ipr-actions button { margin-right:8px; }
            .dir-ipr-input { min-width: 260px; }
        </style>
        <script>
            (function(){
                const form = document.getElementById('dir-ipr-form');
                if (!form) return;

                function closest(el, sel){ while (el && !el.matches(sel)) el = el.parentElement; return el; }

                function tmplRule(index) {
                    return `
<div class="dir-ipr-rule" data-index="${index}">
  <div class="dir-ipr-flex">
    <label>Folder or page:
      <input class="regular-text dir-ipr-input" name="<?php echo esc_js(self::OPTION); ?>[rules][${index}][path]" type="text" placeholder="/protected or /page-slug" />
    </label>
    <label>
      <input type="checkbox" name="<?php echo esc_js(self::OPTION); ?>[rules][${index}][restrict_children]" value="1" />
      Restrict children
    </label>
  </div>
  <div class="dir-ipr-groups">
    <h4>Groups</h4>
    <div class="dir-ipr-groups-wrap"></div>
    <p class="dir-ipr-actions">
      <button class="button add-group" type="button">+ Add Group</button>
      <button class="button button-link-delete remove-rule" type="button">Remove Rule</button>
    </p>
  </div>
</div>`;
                }

                function tmplGroup(rIdx, gIdx) {
                    return `
<div class="dir-ipr-group" data-gindex="${gIdx}">
  <div class="dir-ipr-flex">
    <label>Group name:
      <input class="regular-text dir-ipr-input" name="<?php echo esc_js(self::OPTION); ?>[rules][${rIdx}][groups][${gIdx}][name]" type="text" placeholder="admin2" />
    </label>
  </div>
  <div class="dir-ipr-users">
    <h5>Users</h5>
    <div class="dir-ipr-users-wrap"></div>
    <p class="dir-ipr-actions">
      <button class="button add-user" type="button">+ Add User</button>
      <button class="button button-link-delete remove-group" type="button">Remove Group</button>
    </p>
  </div>
</div>`;
                }

                function tmplUser(rIdx, gIdx, uIdx) {
                    return `
<div class="dir-ipr-user" data-uindex="${uIdx}">
  <div class="dir-ipr-flex">
    <label>User name:
      <input class="regular-text" name="<?php echo esc_js(self::OPTION); ?>[rules][${rIdx}][groups][${gIdx}][users][${uIdx}][username]" type="text" placeholder="alice" />
    </label>
    <label>IP addresses (comma-separated):
      <input class="regular-text" name="<?php echo esc_js(self::OPTION); ?>[rules][${rIdx}][groups][${gIdx}][users][${uIdx}][ips]" type="text" placeholder="1.2.3.4, 5.6.7.8" />
    </label>
    <button class="button button-link-delete remove-user" type="button">Remove</button>
  </div>
</div>`;
                }

                function recomputeIndexes(container, selector, attr) {
                    // Not re-indexing deeply to keep code simple; we append only.
                }

                form.addEventListener('click', function(e){
                    const t = e.target;

                    // Add Rule
                    if (t.classList.contains('add-rule')) {
                        e.preventDefault();
                        const rulesWrap = form.querySelector('.dir-ipr-rules-wrap');
                        const rIndex = rulesWrap ? rulesWrap.children.length : 0;
                        rulesWrap.insertAdjacentHTML('beforeend', tmplRule(rIndex));
                        return;
                    }

                    // Remove Rule
                    if (t.classList.contains('remove-rule')) {
                        e.preventDefault();
                        const rule = closest(t, '.dir-ipr-rule');
                        if (rule) rule.remove();
                        return;
                    }

                    // Add Group
                    if (t.classList.contains('add-group')) {
                        e.preventDefault();
                        const rule = closest(t, '.dir-ipr-rule');
                        const rIdx = Array.prototype.indexOf.call(rule.parentNode.children, rule);
                        const wrap = rule.querySelector('.dir-ipr-groups-wrap');
                        const gIdx = wrap.children.length;
                        wrap.insertAdjacentHTML('beforeend', tmplGroup(rIdx, gIdx));
                        return;
                    }

                    // Remove Group
                    if (t.classList.contains('remove-group')) {
                        e.preventDefault();
                        const grp = closest(t, '.dir-ipr-group');
                        if (grp) grp.remove();
                        return;
                    }

                    // Add User
                    if (t.classList.contains('add-user')) {
                        e.preventDefault();
                        const group = closest(t, '.dir-ipr-group');
                        const rule = closest(t, '.dir-ipr-rule');
                        const rIdx = Array.prototype.indexOf.call(rule.parentNode.children, rule);
                        const gIdx = Array.prototype.indexOf.call(group.parentNode.children, group);
                        const wrap = group.querySelector('.dir-ipr-users-wrap');
                        const uIdx = wrap.children.length;
                        wrap.insertAdjacentHTML('beforeend', tmplUser(rIdx, gIdx, uIdx));
                        return;
                    }

                    // Remove User
                    if (t.classList.contains('remove-user')) {
                        e.preventDefault();
                        const usr = closest(t, '.dir-ipr-user');
                        if (usr) usr.remove();
                        return;
                    }
                });
            })();
        </script>
    <?php }

    public static function render_rules_field(): void {
        $opt = get_option(self::OPTION, ['rules' => []]);
        $rules = is_array($opt['rules'] ?? null) ? $opt['rules'] : [];

        echo '<div class="dir-ipr-rules">';
        echo '<div class="dir-ipr-rules-wrap">';

        if (!empty($rules)) {
            foreach ($rules as $rIndex => $rule) {
                $path = esc_attr($rule['path'] ?? '');
                $rc   = !empty($rule['restrict_children']);
                echo '<div class="dir-ipr-rule" data-index="'.esc_attr($rIndex).'">';
                echo '  <div class="dir-ipr-flex">';
                echo '    <label>Folder or page: <input class="regular-text dir-ipr-input" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][path]" type="text" value="'.$path.'" placeholder="/protected or /page-slug" /></label>';
                echo '    <label><input type="checkbox" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][restrict_children]" value="1" '.checked($rc, true, false).' /> Restrict children</label>';
                echo '  </div>';

                echo '  <div class="dir-ipr-groups"><h4>Groups</h4><div class="dir-ipr-groups-wrap">';
                $groups = is_array($rule['groups'] ?? null) ? $rule['groups'] : [];
                foreach ($groups as $gIndex => $group) {
                    $gname = esc_attr($group['name'] ?? '');
                    echo '<div class="dir-ipr-group" data-gindex="'.esc_attr($gIndex).'">';
                    echo '  <div class="dir-ipr-flex">';
                    echo '    <label>Group name: <input class="regular-text dir-ipr-input" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][groups]['.$gIndex.'][name]" type="text" value="'.$gname.'" placeholder="admin2" /></label>';
                    echo '  </div>';

                    echo '  <div class="dir-ipr-users"><h5>Users</h5><div class="dir-ipr-users-wrap">';
                    $users = is_array($group['users'] ?? null) ? $group['users'] : [];
                    foreach ($users as $uIndex => $user) {
                        $uname = esc_attr($user['username'] ?? '');
                        $ips   = esc_attr($user['ips'] ?? '');
                        echo '<div class="dir-ipr-user" data-uindex="'.esc_attr($uIndex).'">';
                        echo '  <div class="dir-ipr-flex">';
                        echo '    <label>User name: <input class="regular-text" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][groups]['.$gIndex.'][users]['.$uIndex.'][username]" type="text" value="'.$uname.'" placeholder="alice" /></label>';
                        echo '    <label>IP addresses (comma-separated): <input class="regular-text" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][groups]['.$gIndex.'][users]['.$uIndex.'][ips]" type="text" value="'.$ips.'" placeholder="1.2.3.4, 5.6.7.8" /></label>';
                        echo '    <button class="button button-link-delete remove-user" type="button">Remove</button>';
                        echo '  </div>';
                        echo '</div>';
                    }
                    echo '    </div>'; // users-wrap
                    echo '    <p class="dir-ipr-actions"><button class="button add-user" type="button">+ Add User</button> <button class="button button-link-delete remove-group" type="button">Remove Group</button></p>';
                    echo '  </div>'; // users
                    echo '</div>'; // group
                }
                echo '  </div>'; // groups-wrap
                echo '  <p class="dir-ipr-actions"><button class="button add-group" type="button">+ Add Group</button> <button class="button button-link-delete remove-rule" type="button">Remove Rule</button></p>';
                echo '  </div>'; // groups
                echo '</div>'; // rule
            }
        }

        echo '</div>'; // rules-wrap
        echo '<p class="dir-ipr-actions"><button class="button button-primary add-rule" type="button">+ Add Rule</button></p>';
        echo '</div>';
    }

    /* -------------------- Runtime restriction -------------------- */

    public static function maybe_block_request(): void {
        // Skip admin area and administrators (exception for WordPress admin)
        if ( is_admin() || current_user_can('manage_options') ) {
            return;
        }

        $opt = get_option(self::OPTION, ['rules' => []]);
        $rules = is_array($opt['rules'] ?? null) ? $opt['rules'] : [];
        if (empty($rules)) return;

        $req_path = self::current_request_path(); // '/something'
        if ($req_path === '') return;

        // Find first matching rule
        foreach ($rules as $rule) {
            $path   = self::normalize_path((string)($rule['path'] ?? ''));
            if ($path === '') continue;
            $is_child = !empty($rule['restrict_children']);

            if ( self::path_matches($req_path, $path, (bool)$is_child) ) {
                if ( self::is_allowed_for_current_user($rule) ) {
                    return; // allowed, do nothing
                }
                // Block with 403
                status_header(403);
                nocache_headers();
                wp_die(
                    esc_html__('Access forbidden by Directory IP Restrictor.', 'directory-ip-restrictor'),
                    esc_html__('Forbidden', 'directory-ip-restrictor'),
                    ['response' => 403]
                );
            }
        }
    }

    /* -------------------- Helpers -------------------- */

    private static function current_request_path(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') return '';
        $uri = strtok($uri, '?'); // strip query
        return self::normalize_path($uri);
    }

    private static function normalize_path(string $p): string {
        $p = trim($p);
        if ($p === '') return '';
        // ensure leading slash, no trailing slash unless root
        if ($p[0] !== '/') $p = '/' . $p;
        if ($p !== '/' ) $p = rtrim($p, '/');
        return $p;
    }

    private static function path_matches(string $req, string $rulePath, bool $restrict_children): bool {
        if ($restrict_children) {
            if ($req === $rulePath) return true;
            return (strpos($req, $rulePath . '/') === 0);
        }
        return $req === $rulePath;
    }

    private static function is_allowed_for_current_user(array $rule): bool {
        if (!is_user_logged_in()) return false;

        $user = wp_get_current_user();
        $uname = $user ? (string)$user->user_login : '';
        if ($uname === '') return false;

        $ip = self::client_ip();

        $groups = is_array($rule['groups'] ?? null) ? $rule['groups'] : [];
        foreach ($groups as $grp) {
            $users = is_array($grp['users'] ?? null) ? $grp['users'] : [];
            foreach ($users as $u) {
                $matchUser = isset($u['username']) && strtolower($u['username']) === strtolower($uname);
                if (!$matchUser) continue;

                $ips = isset($u['ips']) ? (string)$u['ips'] : '';
                $allowed = self::comma_list_to_array($ips);
                if (empty($allowed)) continue;

                // Simple exact IP match (keep it simple). Extend to CIDR if needed.
                foreach ($allowed as $aip) {
                    if ($ip === $aip) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function comma_list_to_array(string $csv): array {
        if ($csv === '') return [];
        $parts = array_map('trim', explode(',', $csv));
        $parts = array_filter($parts, function($v){ return $v !== ''; });
        return array_values($parts);
    }

    private static function client_ip(): string {
        // Keep simple and predictable; you can extend with HTTP_X_FORWARDED_FOR if you trust proxies/CDN
        return isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
    }
}

DIR_IPR_Plugin::bootstrap();
