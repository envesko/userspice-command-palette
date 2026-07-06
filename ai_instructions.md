# Command Palette - AI instructions (Envesko)

Keyboard-driven command overlay (Ctrl+K / Ctrl+/) for UserSpice 6.x.
## Behaviour
- Renders from `footer.php` when the plugin is active and the viewer passes `CmdPalette_userCanAccess()`.
- Default shortcuts: Ctrl+K and Ctrl+/; configurable on the configure page.
- Static commands come from `CmdPalette_register()`; optional live user search via prefix (default `user:`).
- Without a site layer, registers generic navigation (Dashboard, UserSpice Admin, Page Manager, Plugins).

## Site extension hooks
- `commandpalette_site_bootstrap()` - register commands before render
- `commandpalette_site_config()` - return extra JSON boot keys (`searchUrl`, `accountManagerRoot`, etc.)
- Sites add their own commands via these hooks in `usersc/includes/` without editing the plugin

## Storage
- `commandpalette_config` table (single row, `SettingsJson` column). Keys: `enabled`, `access_mode`
  (`master` | `permissions` | `logged_in`), `permission_ids` (int array when mode is `permissions`),
  shortcuts, `search_prefix`, `show_footer_hint`. Table dropped on plugin delete via `delete.php`.

## Lifecycle files
install (create table + seed) / activate / uninstall (deRegisterHooks) / delete (drop table) /
migrate / configure / update.php stub.

## Custom themes
Themes that skip `html_footer.php` must manually include active plugin footers before `</body>`.

## Key conventions
UserSpice 6.1.0 tested. `Input::get` + `Token::check` on configure POST. Access via
`CmdPalette_userCanAccess()` (not hierarchical permission levels). Bundled user search at
`assets/parsers/user_search.php`, same gate as the palette. Future schema changes go in
`migrate.php`; `update.php` is deprecated.
