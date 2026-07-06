<?php

/**
 * Command Palette - portable UserSpice plugin API.
 *
 * CmdPalette_register($cmd)  - register a navigable command
 * CmdPalette_setContext($ctx) - page context (e.g. current user drill-in)
 * CmdPalette_render()         - emit assets + boot config (idempotent)
 */

if (!function_exists('CmdPalette_resetConfigCache')) {
    function CmdPalette_resetConfigCache()
    {
        $GLOBALS['cmdpalette_config_row'] = null;
        $GLOBALS['cmdpalette_config_row_loaded'] = false;
    }
}

if (!function_exists('CmdPalette_configRow')) {
    function CmdPalette_configRow()
    {
        global $db;
        if (!empty($GLOBALS['cmdpalette_config_row_loaded'])) {
            return $GLOBALS['cmdpalette_config_row'] ?: null;
        }
        $GLOBALS['cmdpalette_config_row_loaded'] = true;
        $row = false;
        try {
            $q = $db->query('SELECT * FROM commandpalette_config WHERE id = 1');
            $row = $q->count() ? $q->first() : false;
        } catch (Exception $e) {
            $row = false;
        }
        $GLOBALS['cmdpalette_config_row'] = $row;

        return $row ?: null;
    }
}

if (!function_exists('CmdPalette_decodeSettingsJson')) {
    function CmdPalette_decodeSettingsJson($raw)
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('CmdPalette_defaultSettings')) {
    function CmdPalette_defaultSettings()
    {
        return [
            'enabled' => true,
            'access_mode' => 'permissions',
            'permission_ids' => [2],
            'shortcuts' => CmdPalette_defaultShortcuts(),
            'show_footer_hint' => true,
            'search_prefix' => 'user:',
        ];
    }
}

if (!function_exists('CmdPalette_ensureConfigTable')) {
    function CmdPalette_ensureConfigTable()
    {
        global $db;

        $db->query('CREATE TABLE IF NOT EXISTS commandpalette_config (
            id INT(11) UNSIGNED NOT NULL PRIMARY KEY,
            SettingsJson MEDIUMTEXT NULL
        )');

        return !$db->error();
    }
}

if (!function_exists('CmdPalette_seedSettingsIfEmpty')) {
    function CmdPalette_seedSettingsIfEmpty()
    {
        global $db;

        if ($db->query('SELECT id FROM commandpalette_config WHERE id = 1')->count() > 0) {
            return true;
        }

        $defaults = CmdPalette_defaultSettings();
        $json = json_encode($defaults, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $db->insert('commandpalette_config', ['id' => 1, 'SettingsJson' => $json]);

        return !$db->error();
    }
}

if (!function_exists('CmdPalette_defaultShortcuts')) {
    function CmdPalette_defaultShortcuts()
    {
        return [
            'ctrl_k' => true,
            'ctrl_slash' => true,
            'custom_enabled' => false,
            'custom_key' => 'p',
        ];
    }
}

if (!function_exists('CmdPalette_normalizeShortcuts')) {
    /**
     * Merge stored shortcuts with defaults; ensure at least one opener stays enabled.
     *
     * @return array{ctrl_k:bool,ctrl_slash:bool,custom_enabled:bool,custom_key:string}
     */
    function CmdPalette_normalizeShortcuts(array $raw)
    {
        $defaults = CmdPalette_defaultShortcuts();
        $shortcuts = array_merge($defaults, array_intersect_key($raw, $defaults));

        $shortcuts['ctrl_k'] = !empty($shortcuts['ctrl_k']);
        $shortcuts['ctrl_slash'] = !empty($shortcuts['ctrl_slash']);
        $shortcuts['custom_enabled'] = !empty($shortcuts['custom_enabled']);

        $key = strtolower(trim((string) ($shortcuts['custom_key'] ?? '')));
        if ($key === '' || !CmdPalette_isValidCustomShortcutKey($key)) {
            $key = $defaults['custom_key'];
        }
        $shortcuts['custom_key'] = $key;

        $anyEnabled = $shortcuts['ctrl_k']
            || $shortcuts['ctrl_slash']
            || ($shortcuts['custom_enabled'] && $shortcuts['custom_key'] !== '');
        if (!$anyEnabled) {
            $shortcuts['ctrl_k'] = true;
        }

        return $shortcuts;
    }
}

if (!function_exists('CmdPalette_isValidCustomShortcutKey')) {
    function CmdPalette_isValidCustomShortcutKey($key)
    {
        $key = strtolower(trim((string) $key));
        if ($key === '') {
            return false;
        }

        // Single printable key (letter, digit, or common punctuation).
        // # delimiter avoids / inside the class terminating the pattern early.
        return (bool) preg_match('#^[a-z0-9.;,\[\]/\-=\'`~]$#', $key);
    }
}

if (!function_exists('CmdPalette_normalizeSearchPrefix')) {
    function CmdPalette_normalizeSearchPrefix($raw)
    {
        $prefix = strtolower(trim((string) $raw));
        if ($prefix === '' || !preg_match('/^[a-z][a-z0-9_]*:$/', $prefix)) {
            return 'user:';
        }

        return $prefix;
    }
}

if (!function_exists('CmdPalette_normalizePermissionIds')) {
    /**
     * @param mixed $raw
     *
     * @return int[]
     */
    function CmdPalette_normalizePermissionIds($raw)
    {
        if (!is_array($raw)) {
            $raw = [(int) $raw];
        }

        $ids = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}

if (!function_exists('CmdPalette_normalizeAccessMode')) {
    function CmdPalette_normalizeAccessMode($raw)
    {
        $mode = strtolower(trim((string) $raw));

        return in_array($mode, ['master', 'permissions', 'logged_in'], true) ? $mode : 'permissions';
    }
}

if (!function_exists('CmdPalette_normalizeSettings')) {
    function CmdPalette_normalizeSettings(array $decoded)
    {
        if (!isset($decoded['shortcuts']) || !is_array($decoded['shortcuts'])) {
            $decoded['shortcuts'] = CmdPalette_defaultShortcuts();
        }

        $decoded['shortcuts'] = CmdPalette_normalizeShortcuts($decoded['shortcuts']);
        $decoded['enabled'] = !array_key_exists('enabled', $decoded) || !empty($decoded['enabled']);
        $decoded['show_footer_hint'] = !array_key_exists('show_footer_hint', $decoded) || !empty($decoded['show_footer_hint']);
        $decoded['search_prefix'] = CmdPalette_normalizeSearchPrefix($decoded['search_prefix'] ?? 'user:');
        $decoded['access_mode'] = CmdPalette_normalizeAccessMode($decoded['access_mode'] ?? 'permissions');
        $decoded['permission_ids'] = CmdPalette_normalizePermissionIds($decoded['permission_ids'] ?? [2]);
        if ($decoded['access_mode'] === 'permissions' && $decoded['permission_ids'] === []) {
            $decoded['permission_ids'] = [2];
        }

        return $decoded;
    }
}

if (!function_exists('CmdPalette_getSettings')) {
    function CmdPalette_getSettings($forceReload = false)
    {
        static $settings = null;
        if ($forceReload) {
            $settings = null;
            CmdPalette_resetConfigCache();
        }
        if ($settings !== null) {
            return $settings;
        }

        $defaults = CmdPalette_defaultSettings();

        $row = CmdPalette_configRow();
        if ($row && isset($row->SettingsJson)) {
            $decoded = CmdPalette_decodeSettingsJson($row->SettingsJson);
            if ($decoded !== null) {
                $settings = CmdPalette_normalizeSettings(array_merge($defaults, $decoded));

                return $settings;
            }
        }

        $settings = CmdPalette_normalizeSettings($defaults);

        return $settings;
    }
}

if (!function_exists('CmdPalette_saveSettings')) {
    function CmdPalette_saveSettings(array $patch)
    {
        $current = CmdPalette_getSettings();
        if (isset($patch['search_prefix'])) {
            $patch['search_prefix'] = CmdPalette_normalizeSearchPrefix($patch['search_prefix']);
        }
        if (isset($patch['access_mode'])) {
            $patch['access_mode'] = CmdPalette_normalizeAccessMode($patch['access_mode']);
        }
        if (array_key_exists('permission_ids', $patch)) {
            $patch['permission_ids'] = CmdPalette_normalizePermissionIds($patch['permission_ids']);
        }
        if (array_key_exists('enabled', $patch)) {
            $patch['enabled'] = !empty($patch['enabled']);
        }
        if (array_key_exists('show_footer_hint', $patch)) {
            $patch['show_footer_hint'] = !empty($patch['show_footer_hint']);
        }
        if (isset($patch['shortcuts']) && is_array($patch['shortcuts'])) {
            $patch['shortcuts'] = CmdPalette_normalizeShortcuts(
                array_merge($current['shortcuts'] ?? CmdPalette_defaultShortcuts(), $patch['shortcuts'])
            );
        }
        $next = array_merge($current, $patch);
        if (isset($next['shortcuts'])) {
            $next['shortcuts'] = CmdPalette_normalizeShortcuts($next['shortcuts']);
        }

        global $db;
        $json = json_encode($next, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        try {
            if ($db->query('SELECT id FROM commandpalette_config WHERE id = 1')->count() === 0) {
                $db->insert('commandpalette_config', ['id' => 1, 'SettingsJson' => $json]);
            } else {
                $db->update('commandpalette_config', 1, ['SettingsJson' => $json]);
            }
            if ($db->error()) {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }

        CmdPalette_resetConfigCache();
        CmdPalette_getSettings(true);

        return true;
    }
}

if (!function_exists('CmdPalette_resetRegistry')) {
    /** @internal test / bootstrap helper */
    function CmdPalette_resetRegistry()
    {
        $GLOBALS['cmdpalette_commands'] = [];
        $GLOBALS['cmdpalette_context'] = [];
    }
}

if (!function_exists('CmdPalette_invokeSiteBootstrap')) {
    function CmdPalette_invokeSiteBootstrap()
    {
        if (function_exists('commandpalette_site_bootstrap')) {
            commandpalette_site_bootstrap();
        } elseif (function_exists('commandpalette_envesko_bootstrap')) {
            // Undocumented alias used by Envesko production sites only.
            commandpalette_envesko_bootstrap();
        }
    }
}

if (!function_exists('CmdPalette_invokeSiteConfig')) {
    /**
     * @return array<string,mixed>
     */
    function CmdPalette_invokeSiteConfig()
    {
        if (function_exists('commandpalette_site_config')) {
            $cfg = commandpalette_site_config();

            return is_array($cfg) ? $cfg : [];
        }
        if (function_exists('commandpalette_envesko_config')) {
            // Undocumented alias used by Envesko production sites only.
            $cfg = commandpalette_envesko_config();

            return is_array($cfg) ? $cfg : [];
        }

        return [];
    }
}

if (!function_exists('CmdPalette_register')) {
    /**
     * @param array $cmd id, label, href, group?, keywords?, icon?
     */
    function CmdPalette_register(array $cmd)
    {
        if (empty($cmd['id']) || empty($cmd['label']) || empty($cmd['href'])) {
            return false;
        }

        if (!isset($GLOBALS['cmdpalette_commands'])) {
            $GLOBALS['cmdpalette_commands'] = [];
        }

        $id = (string) $cmd['id'];
        if (isset($GLOBALS['cmdpalette_commands'][$id])) {
            return true;
        }

        $entry = [
            'id' => $id,
            'label' => (string) $cmd['label'],
            'href' => (string) $cmd['href'],
            'group' => isset($cmd['group']) ? (string) $cmd['group'] : 'Commands',
            'keywords' => [],
            'icon' => isset($cmd['icon']) ? (string) $cmd['icon'] : '',
        ];

        if (!empty($cmd['keywords'])) {
            $kw = $cmd['keywords'];
            if (!is_array($kw)) {
                $kw = preg_split('/\s*,\s*/', (string) $kw, -1, PREG_SPLIT_NO_EMPTY);
            }
            $entry['keywords'] = array_values(array_map('strval', $kw));
        }

        $GLOBALS['cmdpalette_commands'][$id] = $entry;

        return true;
    }
}

if (!function_exists('CmdPalette_setContext')) {
    function CmdPalette_setContext(array $ctx)
    {
        if (!isset($GLOBALS['cmdpalette_context'])) {
            $GLOBALS['cmdpalette_context'] = [];
        }
        $GLOBALS['cmdpalette_context'] = array_merge($GLOBALS['cmdpalette_context'], $ctx);
    }
}

if (!function_exists('CmdPalette_getCommands')) {
    function CmdPalette_getCommands()
    {
        return array_values($GLOBALS['cmdpalette_commands'] ?? []);
    }
}

if (!function_exists('CmdPalette_userCanAccess')) {
    function CmdPalette_userCanAccess()
    {
        global $user, $master_account;

        if (!isset($user) || !is_object($user) || !$user->isLoggedIn()) {
            return false;
        }

        $settings = CmdPalette_getSettings();
        $mode = CmdPalette_normalizeAccessMode($settings['access_mode'] ?? 'permissions');

        if ($mode === 'logged_in') {
            return true;
        }

        if ($mode === 'master') {
            return is_array($master_account ?? null)
                && in_array((int) $user->data()->id, $master_account, true);
        }

        $permIds = CmdPalette_normalizePermissionIds($settings['permission_ids'] ?? [2]);
        if ($permIds === []) {
            return false;
        }

        return hasPerm($permIds);
    }
}

if (!function_exists('CmdPalette_shouldRender')) {
    function CmdPalette_shouldRender()
    {
        $settings = CmdPalette_getSettings();
        if (empty($settings['enabled'])) {
            return false;
        }

        return CmdPalette_userCanAccess();
    }
}

if (!function_exists('CmdPalette_registerDefaultSystemCommands')) {
    /**
     * Standard UserSpice 6.x admin views for fresh installs.
     *
     * @param array{custom_settings_label?:string,custom_settings_keywords?:string[]} $opts
     */
    function CmdPalette_registerDefaultSystemCommands(array $opts = [])
    {
        global $us_url_root;

        if (!function_exists('CmdPalette_register')) {
            return;
        }

        $admin = $us_url_root.'users/admin.php';
        $customLabel = $opts['custom_settings_label'] ?? 'Custom Settings';
        $customKeywords = $opts['custom_settings_keywords'] ?? ['custom', 'settings', 'config'];

        $commands = [
            ['id' => 'us-admin', 'label' => 'UserSpice Admin', 'href' => $admin, 'group' => 'System', 'keywords' => ['userspice', 'acp', 'dashboard', 'admin'], 'icon' => 'fa-cog'],
            ['id' => 'us-users', 'label' => 'User Manager', 'href' => $admin.'?view=users', 'group' => 'System', 'keywords' => ['users', 'accounts', 'members'], 'icon' => 'fa-user'],
            ['id' => 'us-logs', 'label' => 'System Logs', 'href' => $admin.'?view=logs', 'group' => 'System', 'keywords' => ['log', 'logs', 'debug'], 'icon' => 'fa-list-ol'],
            ['id' => 'us-plugins', 'label' => 'Plugin Manager', 'href' => $admin.'?view=plugins', 'group' => 'System', 'keywords' => ['plugins', 'extensions'], 'icon' => 'fa-plug'],
            ['id' => 'us-pages', 'label' => 'Page Manager', 'href' => $admin.'?view=pages', 'group' => 'System', 'keywords' => ['pages', 'routes'], 'icon' => 'fa-file'],
            ['id' => 'us-security', 'label' => 'Security Dashboard', 'href' => $admin.'?view=security', 'group' => 'System', 'keywords' => ['security', 'encryption', 'auth'], 'icon' => 'fa-shield'],
            ['id' => 'us-security-logs', 'label' => 'Security Logs', 'href' => $admin.'?view=security_logs', 'group' => 'System', 'keywords' => ['security', 'audit', 'lockout'], 'icon' => 'fa-lock'],
            ['id' => 'us-permissions', 'label' => 'Permissions', 'href' => $admin.'?view=permissions', 'group' => 'System', 'keywords' => ['permissions', 'roles', 'access'], 'icon' => 'fa-unlock-alt'],
            ['id' => 'us-general', 'label' => 'General Settings', 'href' => $admin.'?view=general', 'group' => 'System', 'keywords' => ['settings', 'site', 'general'], 'icon' => 'fa-check'],
            ['id' => 'us-custom', 'label' => $customLabel, 'href' => $admin.'?view=custom', 'group' => 'System', 'keywords' => $customKeywords, 'icon' => 'fa-wrench'],
            ['id' => 'us-email', 'label' => 'Email Settings', 'href' => $admin.'?view=email', 'group' => 'System', 'keywords' => ['email', 'smtp', 'mail'], 'icon' => 'fa-envelope'],
            ['id' => 'us-reg', 'label' => 'Registration Settings', 'href' => $admin.'?view=reg', 'group' => 'System', 'keywords' => ['registration', 'signup', 'users'], 'icon' => 'fa-users'],
            ['id' => 'us-updates', 'label' => 'Updates', 'href' => $admin.'?view=updates', 'group' => 'System', 'keywords' => ['updates', 'upgrade', 'version'], 'icon' => 'fa-arrow-circle-o-up'],
            ['id' => 'us-backup', 'label' => 'Backups', 'href' => $admin.'?view=backup', 'group' => 'System', 'keywords' => ['backup', 'restore', 'database'], 'icon' => 'fa-database'],
            ['id' => 'us-cron', 'label' => 'Cron Jobs', 'href' => $admin.'?view=cron', 'group' => 'System', 'keywords' => ['cron', 'scheduled', 'tasks'], 'icon' => 'fa-terminal'],
            ['id' => 'us-templates', 'label' => 'Templates', 'href' => $admin.'?view=templates', 'group' => 'System', 'keywords' => ['templates', 'themes', 'skins'], 'icon' => 'fa-eye'],
            ['id' => 'us-spice', 'label' => 'Spice Shaker', 'href' => $admin.'?view=spice', 'group' => 'System', 'keywords' => ['spice', 'download', 'addons'], 'icon' => 'fa-user-secret'],
            ['id' => 'us-menus', 'label' => 'UltraMenu', 'href' => $admin.'?view=menus', 'group' => 'System', 'keywords' => ['menus', 'navigation', 'ultramenu'], 'icon' => 'fa-bars'],
            ['id' => 'us-nav', 'label' => 'Navigation (Classic)', 'href' => $admin.'?view=nav', 'group' => 'System', 'keywords' => ['navigation', 'nav', 'classic'], 'icon' => 'fa-rocket'],
            ['id' => 'us-access', 'label' => 'Dashboard Access', 'href' => $admin.'?view=access', 'group' => 'System', 'keywords' => ['dashboard', 'access', 'widgets'], 'icon' => 'fa-file-code-o'],
            ['id' => 'us-ip', 'label' => 'IP Manager', 'href' => $admin.'?view=ip', 'group' => 'System', 'keywords' => ['ip', 'ban', 'blacklist'], 'icon' => 'fa-warning'],
            ['id' => 'us-sessions', 'label' => 'Sessions', 'href' => $admin.'?view=sessions', 'group' => 'System', 'keywords' => ['sessions', 'login'], 'icon' => 'fa-clock-o'],
            ['id' => 'us-rate-limits', 'label' => 'Rate Limits', 'href' => $admin.'?view=rate_limits', 'group' => 'System', 'keywords' => ['rate', 'limits', 'throttle'], 'icon' => 'fa-tachometer'],
            ['id' => 'us-user-tags', 'label' => 'User Tags', 'href' => $admin.'?view=user_tags', 'group' => 'System', 'keywords' => ['tags', 'labels', 'users'], 'icon' => 'fa-tags'],
            ['id' => 'us-bugs', 'label' => 'Bug Report', 'href' => $admin.'?view=bugs', 'group' => 'System', 'keywords' => ['bug', 'report', 'issue'], 'icon' => 'fa-bug'],
            ['id' => 'us-oauth', 'label' => 'OAuth Server', 'href' => $admin.'?view=oauth', 'group' => 'System', 'keywords' => ['oauth', 'server', 'sso'], 'icon' => 'fa-exchange'],
            ['id' => 'us-oauth-client', 'label' => 'OAuth Clients', 'href' => $admin.'?view=oauth_client', 'group' => 'System', 'keywords' => ['oauth', 'clients', 'api'], 'icon' => 'fa-key'],
        ];

        foreach ($commands as $item) {
            CmdPalette_register($item);
        }
    }
}

if (!function_exists('CmdPalette_render')) {
    function CmdPalette_render()
    {
        global $abs_us_root, $us_url_root;

        static $rendered = false;
        if ($rendered || !CmdPalette_shouldRender()) {
            return;
        }
        $rendered = true;

        if (function_exists('CmdPalette_invokeSiteBootstrap')) {
            CmdPalette_invokeSiteBootstrap();
        }

        // Site extensions may register defaults when no site layer is present.
        if (empty($GLOBALS['cmdpalette_commands'])) {
            CmdPalette_register([
                'id' => 'dashboard',
                'label' => 'Dashboard',
                'href' => $us_url_root.'dashboard/',
                'group' => 'Navigation',
                'keywords' => ['home'],
                'icon' => 'fa-home',
            ]);
            if (function_exists('hasPerm') && hasPerm(2)) {
                CmdPalette_registerDefaultSystemCommands();
            }
        }

        $settings = CmdPalette_getSettings();
        $pluginRoot = $us_url_root.'usersc/plugins/commandpalette/';
        $cssPath = $abs_us_root.$us_url_root.'usersc/plugins/commandpalette/assets/css/commandpalette.css';
        $jsPath = $abs_us_root.$us_url_root.'usersc/plugins/commandpalette/assets/js/commandpalette.js';
        $cssVer = is_readable($cssPath) ? (string) @filemtime($cssPath) : '1';
        $jsVer = is_readable($jsPath) ? (string) @filemtime($jsPath) : '1';

        $shortcuts = CmdPalette_normalizeShortcuts($settings['shortcuts'] ?? []);
        $searchPrefix = CmdPalette_normalizeSearchPrefix($settings['search_prefix'] ?? 'user:');
        $placeholder = 'Type a command, or '.$searchPrefix.'username to find a user';
        $payload = [
            'shortcuts' => $shortcuts,
            'searchPrefix' => $searchPrefix,
            'showFooterHint' => !empty($settings['show_footer_hint']),
            'csrf' => Token::generate(),
            'commands' => CmdPalette_getCommands(),
            'context' => $GLOBALS['cmdpalette_context'] ?? [],
        ];

        if (function_exists('CmdPalette_invokeSiteConfig')) {
            $extra = CmdPalette_invokeSiteConfig();
            if (is_array($extra) && $extra !== []) {
                $payload = array_merge($payload, $extra);
            }
        }

        if (empty($payload['searchUrl']) && CmdPalette_userCanAccess()) {
            $payload['searchUrl'] = $pluginRoot.'assets/parsers/user_search.php';
        }
        if (empty($payload['accountManagerRoot'])) {
            $payload['accountManagerRoot'] = $us_url_root.'users/admin.php?view=user&id=';
        }
        ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($pluginRoot.'assets/css/commandpalette.css?v='.$cssVer, ENT_QUOTES); ?>">
<div id="cmdpalette-root" class="cmdpalette" hidden aria-hidden="true">
  <div class="cmdpalette__backdrop" data-cmdpalette-close></div>
  <div class="cmdpalette__panel" role="dialog" aria-modal="true" aria-labelledby="cmdpalette-title">
    <label class="cmdpalette__search-wrap" for="cmdpalette-input">
      <span class="cmdpalette__search-icon" aria-hidden="true"><i class="fa fa-search"></i></span>
      <input type="search" id="cmdpalette-input" class="cmdpalette__input" autocomplete="off" spellcheck="false" placeholder="<?php echo htmlspecialchars($placeholder, ENT_QUOTES); ?>">
      <kbd class="cmdpalette__hint">Esc</kbd>
    </label>
    <p id="cmdpalette-title" class="cmdpalette__sr-only">Command palette</p>
    <ul class="cmdpalette__list" id="cmdpalette-list" role="listbox" aria-label="Commands"></ul>
    <p class="cmdpalette__empty" id="cmdpalette-empty" hidden>No matching commands.</p>
    <div class="cmdpalette__footer">
      <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
      <span><kbd>Enter</kbd> open</span>
      <span class="cmdpalette__shortcut-hint" id="cmdpalette-shortcut-hint"></span>
    </div>
  </div>
</div>
<script type="application/json" id="cmdpalette-data"><?php
            echo json_encode(
                $payload,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
?></script>
<script src="<?php echo htmlspecialchars($pluginRoot.'assets/js/commandpalette.js?v='.$jsVer, ENT_QUOTES); ?>" defer></script>
        <?php
    }
}
