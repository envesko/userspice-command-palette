# Command Palette for UserSpice

**By [Envesko](https://envesko.com).** Keyboard-driven command palette for UserSpice 6.x. Press **Ctrl+K** (or **Ctrl+/**) to open a
search overlay for quick navigation. Portable plugin with a developer API for registering site-specific
commands.

UserSpice can be downloaded from [userspice.com](https://userspice.com/) or
[GitHub](https://github.com/mudmin/UserSpice5).

## Install

### Spice Shaker (recommended)

1. Open **Plugins** in the UserSpice admin panel
2. Search for **Command Palette**
3. Download and install
4. Activate the plugin
5. Open **Configure** to adjust shortcuts and permissions

### Manual

1. Download the release ZIP from GitHub (the zip top folder must be `commandpalette/`)
2. Copy it to `usersc/plugins/commandpalette/`
3. In the plugin manager, click **Install**, then **Activate**
4. Open **Configure**

## Configure

The configure page lets you:

- Enable or disable the palette
- Set who can open the palette: master only, specific permission(s), or any logged-in user
- Choose keyboard shortcuts (Ctrl+K, Ctrl+/, or a custom key)
- Set the user-search prefix (default `user:`)

Default access is **permission #2** (the default admin permission on a fresh UserSpice install). Permission IDs are explicit assignments, not ranked levels.

Settings are stored in the `commandpalette_config` database table (JSON column).

## Developer API

Register commands before the page footer renders (for example in `usersc/includes/` or a page
controller):

```php
CmdPalette_register([
    'id'       => 'my-reports',
    'label'    => 'Open reports',
    'href'     => $us_url_root . 'reports/',
    'group'    => 'Navigation',
    'keywords' => ['analytics', 'stats'],
    'icon'     => 'fa-bar-chart',
]);
```

**Drill-in context** (optional commands for the current record):

```php
CmdPalette_setContext(['user' => [
    'id'       => (int) $row->id,
    'username' => $row->username,
]]);

CmdPalette_register([
    'id'    => 'ctx-billing-' . $row->id,
    'label' => 'Billing for ' . $row->username,
    'href'  => $us_url_root . 'billing/user/' . $row->id . '/',
    'group' => 'Current user',
    'icon'  => 'fa-credit-card',
]);
```

Duplicate `id` values are ignored (first registration wins).

### Site extension hooks

Sites can register their own commands and boot config via hooks in `usersc/includes/` (loaded before
the footer) without editing the plugin:

```php
function commandpalette_site_config() {
    global $us_url_root;

    return [
        'accountManagerRoot' => $us_url_root . 'users/admin.php?view=user&id=',
    ];
}

function commandpalette_site_bootstrap() {
    CmdPalette_register([
        'id'    => 'go-user-search',
        'label' => 'Go to user… (user:username)',
        'href'  => '#user-search',
        'group' => 'Navigation',
        'icon'  => 'fa-search',
    ]);
}
```

### User search

When no site hook overrides it, the plugin wires a built-in parser at
`usersc/plugins/commandpalette/assets/parsers/user_search.php` (web-accessible under the plugin folder).
Type `user:alice` in the palette to search by username, email, or numeric id. Set
`accountManagerRoot` in `commandpalette_site_config()` so result links land on your user profile URL.
Override `searchUrl` only for custom queries or response shaping.

Return shape: `{ "state": true, "results": [{ "id", "username", "label", "href" }] }`.

## Custom themes (important)

The palette loads from the plugin `footer.php` hook. UserSpice core includes active plugin footers
via `users/includes/html_footer.php`.

**If your theme bypasses `html_footer.php`**, you must include active plugin footers yourself before
`</body>`:

```php
function myIncludePluginFooters() {
    global $abs_us_root, $us_url_root, $usplugins;

    static $done = false;
    if ($done || !is_array($usplugins ?? null)) {
        return;
    }

    foreach ($usplugins as $k => $v) {
        if ($v == 1) {
            $footer = $abs_us_root . $us_url_root . 'usersc/plugins/' . $k . '/footer.php';
            if (file_exists($footer)) {
                include $footer;
            }
        }
    }

    $done = true;
}
```

Without this, the palette will not appear on custom themes even when the plugin is active.

## Questions or issues

Open an issue on GitHub. Pull requests welcome; run PHP CS Fixer with the included config before
submitting.

---

© 2026 **[Envesko](https://envesko.com)** — MIT License (see `LICENSE`).

## v1.0.0

Initial release:

- Ctrl+K / Ctrl+/ command palette with configurable shortcuts
- Access control: master account, specific permissions, or any logged-in user
- Configure page with permission checkboxes and developer quick-reference
- Bundled user search (`user:` prefix) at `assets/parsers/user_search.php`
- Site hooks: `commandpalette_site_config`, `commandpalette_site_bootstrap`
- Full UserSpice admin System commands (User Manager, Logs, Plugins, and more)
- DB-backed settings (`commandpalette_config`)
- Spice Shaker branding (`logo.png`, `banner.png`)
