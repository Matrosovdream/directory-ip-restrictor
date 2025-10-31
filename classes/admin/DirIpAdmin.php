<?php
if ( ! defined('ABSPATH') ) exit;

final class DirIpAdmin {
    const OPTION = 'dir_ipr_settings';

    /* ---------------- Settings hooks are wired from DirIpActionAdmin ---------------- */

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
                echo '<p>' . esc_html__('Create rules to restrict specific paths. Access is granted if the user has an allowed role OR their IP matches one of the extra group IPs. User name inside extra groups is just a label.', 'directory-ip-restrictor') . '</p>';
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

    /* ---------------- Sanitize & Render ---------------- */

    public static function sanitize_settings($input) {
        $out = ['rules' => []];

        if (!is_array($input) || empty($input['rules']) || !is_array($input['rules'])) {
            return $out;
        }

        // Prepare list of valid role slugs
        $wp_roles = wp_roles();
        $valid_roles = is_object($wp_roles) && isset($wp_roles->roles) ? array_keys($wp_roles->roles) : [];

        foreach ($input['rules'] as $rule) {
            $path = isset($rule['path']) ? trim(wp_unslash($rule['path'])) : '';
            if ($path === '') continue;

            $restrict_children = !empty($rule['restrict_children']) ? 1 : 0;
            $active            = !empty($rule['active']) ? 1 : 0;

            // Allowed user groups (WordPress roles)
            $sel_roles_raw = isset($rule['allowed_user_groups']) && is_array($rule['allowed_user_groups'])
                ? array_map('sanitize_key', $rule['allowed_user_groups'])
                : [];
            // Keep only valid roles
            $allowed_user_groups = array_values(array_intersect($sel_roles_raw, $valid_roles));

            // Allowed extra groups (custom user/IP blocks)
            $groups_out = [];
            if (!empty($rule['groups']) && is_array($rule['groups'])) {
                foreach ($rule['groups'] as $grp) {
                    $gname = isset($grp['name']) ? sanitize_text_field($grp['name']) : '';
                    $users_out = [];
                    if (!empty($grp['users']) && is_array($grp['users'])) {
                        foreach ($grp['users'] as $u) {
                            $username = isset($u['username']) ? sanitize_text_field($u['username']) : '';
                            // Keep raw textarea; runtime will parse as tab/newline-separated
                            $ips_raw  = isset($u['ips']) ? (string) $u['ips'] : '';
                            // Normalize CRLF to LF and trim edges; keep tabs/newlines
                            $ips      = trim(str_replace(["\r\n", "\r"], "\n", $ips_raw));
                            if ($username !== '' && $ips !== '') {
                                $users_out[] = [
                                    'username' => $username, // label only
                                    'ips'      => $ips,      // tab/newline-separated
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
                'path'                => DirIpHelpers::normalize_path($path),
                'restrict_children'   => $restrict_children,
                'active'              => $active,
                'allowed_user_groups' => $allowed_user_groups, // array of role slugs
                'groups'              => $groups_out,          // extra IP groups
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
            .dir-ipr-flex { display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap; }
            .dir-ipr-actions button { margin-right:8px; }
            .dir-ipr-input { min-width:260px; }
            .dir-ipr-textarea { min-width:320px; width:100%; max-width:700px; }
            .dir-ipr-label { font-weight:600; display:inline-block; margin-bottom:4px; }
            .dir-ipr-select { min-width:320px; }
        </style>
        <script>
            (function(){
                const form = document.getElementById('dir-ipr-form');
                if (!form) return;

                function closest(el, sel){ while (el && !el.matches(sel)) el = el.parentElement; return el; }

                // Prebuild roles options HTML via PHP for use in JS templates
                const rolesOptionsHTML = (function(){
                    <?php
                    $wp_roles = wp_roles();
                    $options = '';
                    if ( is_object($wp_roles) && isset($wp_roles->roles) ) {
                        foreach ($wp_roles->roles as $slug => $role) {
                            $label = isset($role['name']) ? $role['name'] : $slug;
                            $options .= '<option value="'.esc_attr($slug).'">'.esc_html($label).'</option>';
                        }
                    }
                    ?>
                    return <?php echo json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                })();

                function tmplRule(index) {
                    const opt = '<?php echo esc_js(DirIpAdmin::OPTION); ?>';
                    return `
<div class="dir-ipr-rule" data-index="\${index}">
  <div class="dir-ipr-flex" style="align-items:center;">
    <label class="dir-ipr-label" style="margin:0;">Folder or page:</label>
    <input class="regular-text dir-ipr-input" name="\${opt}[rules][\${index}][path]" type="text" placeholder="/protected or /page-slug" />
    <label>
      <input type="checkbox" name="\${opt}[rules][\${index}][restrict_children]" value="1" />
      Restrict children
    </label>
    <label>
      <input type="checkbox" name="\${opt}[rules][\${index}][active]" value="1" checked />
      Active
    </label>
  </div>

  <div class="dir-ipr-roles">
    <h4>Allowed user groups</h4>
    <select class="dir-ipr-select" multiple size="6" name="\${opt}[rules][\${index}][allowed_user_groups][]" aria-label="Allowed user groups">
      \${rolesOptionsHTML}
    </select>
    <p class="description"><?php echo esc_html__('Users with any selected role are allowed automatically for this rule.', 'directory-ip-restrictor'); ?></p>
  </div>

  <div class="dir-ipr-groups">
    <h4>Allowed extra groups</h4>
    <div class="dir-ipr-groups-wrap"></div>
    <p class="dir-ipr-actions">
      <button class="button add-group" type="button">+ Add Group</button>
      <button class="button button-link-delete remove-rule" type="button">Remove Rule</button>
    </p>
  </div>
</div>`;
                }

                function tmplGroup(rIdx, gIdx) {
                    const opt = '<?php echo esc_js(DirIpAdmin::OPTION); ?>';
                    return `
<div class="dir-ipr-group" data-gindex="\${gIdx}">
  <div class="dir-ipr-flex" style="align-items:center;">
    <label class="dir-ipr-label" style="margin:0;">Group name:</label>
    <input class="regular-text dir-ipr-input" name="\${opt}[rules][\${rIdx}][groups][\${gIdx}][name]" type="text" placeholder="admin2" />
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
                    const opt = '<?php echo esc_js(DirIpAdmin::OPTION); ?>';
                    return `
<div class="dir-ipr-user" data-uindex="\${uIdx}">
  <div class="dir-ipr-flex" style="flex-direction:column; align-items:stretch;">
    <label class="dir-ipr-label">User name (label):</label>
    <input class="regular-text" name="\${opt}[rules][\${rIdx}][groups][\${gIdx}][users][\${uIdx}][username]" type="text" placeholder="John Doe" />
    <label class="dir-ipr-label" style="margin-top:8px;">IP addresses (tab/newline-separated):</label>
    <textarea class="dir-ipr-textarea" rows="3" name="\${opt}[rules][\${rIdx}][groups][\${gIdx}][users][\${uIdx}][ips]" placeholder="185.71.88.46&#10;2a03:2880:f003:c07::1&#10;10.0.0.1"></textarea>
    <div style="margin-top:6px;">
      <button class="button button-link-delete remove-user" type="button">Remove</button>
    </div>
  </div>
</div>`;
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
                        const rule  = closest(t, '.dir-ipr-rule');
                        const rIdx  = Array.prototype.indexOf.call(rule.parentNode.children, rule);
                        const gIdx  = Array.prototype.indexOf.call(group.parentNode.children, group);
                        const wrap  = group.querySelector('.dir-ipr-users-wrap');
                        const uIdx  = wrap.children.length;
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
        $opt   = get_option(self::OPTION, ['rules' => []]);
        $rules = is_array($opt['rules'] ?? null) ? $opt['rules'] : [];

        // roles map
        $wp_roles = wp_roles();
        $roles = (is_object($wp_roles) && isset($wp_roles->roles)) ? $wp_roles->roles : [];

        echo '<div class="dir-ipr-rules">';
        echo '<div class="dir-ipr-rules-wrap">';

        if (!empty($rules)) {
            foreach ($rules as $rIndex => $rule) {
                $path = esc_attr($rule['path'] ?? '');
                $rc   = !empty($rule['restrict_children']);
                // Back-compat: default to active if not set
                $act  = array_key_exists('active', $rule) ? (bool)$rule['active'] : true;

                $sel_roles = is_array($rule['allowed_user_groups'] ?? null) ? $rule['allowed_user_groups'] : [];

                echo '<div class="dir-ipr-rule" data-index="'.esc_attr($rIndex).'">';
                echo '  <div class="dir-ipr-flex" style="align-items:center;">';
                echo '    <label class="dir-ipr-label" style="margin:0;">Folder or page:</label>';
                echo '    <input class="regular-text dir-ipr-input" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][path]" type="text" value="'.$path.'" placeholder="/protected or /page-slug" />';
                echo '    <label><input type="checkbox" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][restrict_children]" value="1" '.checked($rc, true, false).' /> ' . esc_html__('Restrict children', 'directory-ip-restrictor') . '</label>';
                echo '    <label><input type="checkbox" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][active]" value="1" '.checked($act, true, false).' /> ' . esc_html__('Active', 'directory-ip-restrictor') . '</label>';
                echo '  </div>';

                echo '  <div class="dir-ipr-roles"><h4>'.esc_html__('Allowed user groups', 'directory-ip-restrictor').'</h4>';
                echo '    <select class="dir-ipr-select" multiple size="6" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][allowed_user_groups][]" aria-label="Allowed user groups">';
                foreach ($roles as $slug => $role) {
                    $label = isset($role['name']) ? $role['name'] : $slug;
                    echo '<option value="'.esc_attr($slug).'" '.selected(in_array($slug, $sel_roles, true), true, false).'>'.esc_html($label).'</option>';
                }
                echo '    </select>';
                echo '    <p class="description">'.esc_html__('Users with any selected role are allowed automatically for this rule.', 'directory-ip-restrictor').'</p>';
                echo '  </div>';

                echo '  <div class="dir-ipr-groups"><h4>'.esc_html__('Allowed extra groups', 'directory-ip-restrictor').'</h4><div class="dir-ipr-groups-wrap">';
                $groups = is_array($rule['groups'] ?? null) ? $rule['groups'] : [];
                foreach ($groups as $gIndex => $group) {
                    $gname = esc_attr($group['name'] ?? '');
                    echo '<div class="dir-ipr-group" data-gindex="'.esc_attr($gIndex).'">';
                    echo '  <div class="dir-ipr-flex" style="align-items:center;">';
                    echo '    <label class="dir-ipr-label" style="margin:0;">Group name:</label>';
                    echo '    <input class="regular-text dir-ipr-input" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][groups]['.$gIndex.'][name]" type="text" value="'.$gname.'" placeholder="admin2" />';
                    echo '  </div>';

                    echo '  <div class="dir-ipr-users"><h5>Users</h5><div class="dir-ipr-users-wrap">';
                    $users = is_array($group['users'] ?? null) ? $group['users'] : [];
                    foreach ($users as $uIndex => $user) {
                        $uname = esc_attr($user['username'] ?? '');
                        $ips   = esc_textarea($user['ips'] ?? '');
                        echo '<div class="dir-ipr-user" data-uindex="'.esc_attr($uIndex).'">';
                        echo '  <div class="dir-ipr-flex" style="flex-direction:column; align-items:stretch;">';
                        echo '    <label class="dir-ipr-label">User name (label):</label>';
                        echo '    <input class="regular-text" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][groups]['.$gIndex.'][users]['.$uIndex.'][username]" type="text" value="'.$uname.'" placeholder="John Doe" />';
                        echo '    <label class="dir-ipr-label" style="margin-top:8px;">IP addresses (tab/newline-separated):</label>';
                        echo '    <textarea class="dir-ipr-textarea" rows="3" name="'.esc_attr(self::OPTION).'[rules]['.$rIndex.'][groups]['.$gIndex.'][users]['.$uIndex.'][ips]" placeholder="185.71.88.46&#10;2a03:2880:f003:c07::1&#10;10.0.0.1">'.$ips.'</textarea>';
                        echo '    <div style="margin-top:6px;"><button class="button button-link-delete remove-user" type="button">Remove</button></div>';
                        echo '  </div>';
                        echo '</div>';
                    }
                    echo '    </div>'; // users-wrap
                    echo '    <p class="dir-ipr-actions"><button class="button add-user" type="button">+ Add User</button> <button class="button button-link-delete remove-group" type="button">Remove Group</button></p>';
                    echo '  </div>'; // users
                    echo '</div>'; // group
                }
                echo '  </div>'; // groups-wrap
                echo '  <p class="dir-ipr-actions"><button class="button button-primary add-rule" type="button">+ Add Rule</button> <button class="button button-link-delete remove-rule" type="button">Remove Rule</button></p>';
                echo '  </div>'; // groups
                echo '</div>'; // rule
            }
        }

        echo '</div>'; // rules-wrap
        echo '<p class="dir-ipr-actions"><button class="button button-primary add-rule" type="button">+ Add Rule</button></p>';
        echo '</div>';
    }
}
