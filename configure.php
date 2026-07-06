<?php
// Command Palette - admin config page. admin.php?view=plugins_config&plugin=commandpalette
if (!in_array($user->data()->id, $master_account)) {
    echo '<div class="alert alert-danger">Access denied.</div>';

    return;
}

include 'plugin_info.php';

$cmdPaletteSettings = CmdPalette_getSettings();
$shortcuts = $cmdPaletteSettings['shortcuts'] ?? CmdPalette_defaultShortcuts();
$errors = $successes = [];

if (Server::get('REQUEST_METHOD') === 'POST') {
    if (!Token::check(Input::get('csrf'))) {
        $errors[] = 'Invalid security token.';
    } else {
        $nextShortcuts = [
            'ctrl_k' => (bool) Input::get('shortcut_ctrl_k'),
            'ctrl_slash' => (bool) Input::get('shortcut_ctrl_slash'),
            'custom_enabled' => (bool) Input::get('shortcut_custom_enabled'),
            'custom_key' => strtolower(trim((string) Input::get('shortcut_custom_key'))),
        ];

        if ($nextShortcuts['custom_enabled']) {
            if ($nextShortcuts['custom_key'] === '') {
                $errors[] = 'Enter a key for the custom shortcut.';
            } elseif (!CmdPalette_isValidCustomShortcutKey($nextShortcuts['custom_key'])) {
                $errors[] = 'Custom key must be a single letter, number, or punctuation key.';
            }
        }

        $rawPrefix = strtolower(trim((string) Input::get('search_prefix')));
        if ($rawPrefix !== '' && $rawPrefix !== CmdPalette_normalizeSearchPrefix($rawPrefix)) {
            $errors[] = 'Search prefix must start with a letter and end with a colon (e.g. user:).';
        }

        if (!$errors) {
            $accessMode = CmdPalette_normalizeAccessMode(Input::get('access_mode'));
            $permIds = Input::get('permission_ids');
            if (!is_array($permIds)) {
                $permIds = [];
            }
            $permIds = CmdPalette_normalizePermissionIds($permIds);
            if ($accessMode === 'permissions' && $permIds === []) {
                $errors[] = 'Select at least one permission when access is limited to specific permissions.';
            }
        }

        if (!$errors) {
            $normalized = CmdPalette_normalizeShortcuts($nextShortcuts);
            $forcedDefault = !$nextShortcuts['ctrl_k']
                && !$nextShortcuts['ctrl_slash']
                && (!$nextShortcuts['custom_enabled'] || $nextShortcuts['custom_key'] === '');
            if ($forcedDefault) {
                $successes[] = 'At least one shortcut is required; Ctrl+K was re-enabled.';
            }

            $patch = [
                'enabled' => (bool) Input::get('enabled'),
                'access_mode' => $accessMode,
                'permission_ids' => $permIds,
                'show_footer_hint' => (bool) Input::get('show_footer_hint'),
                'search_prefix' => $rawPrefix !== '' ? $rawPrefix : 'user:',
                'shortcuts' => $normalized,
            ];

            if (CmdPalette_saveSettings($patch)) {
                if (!$forcedDefault) {
                    $successes[] = 'Settings saved.';
                }
                $cmdPaletteSettings = CmdPalette_getSettings(true);
                $shortcuts = $cmdPaletteSettings['shortcuts'];
            } else {
                $errors[] = 'Could not save settings.';
            }
        }
    }
}

$enabledLabels = [];
if (!empty($shortcuts['ctrl_k'])) {
    $enabledLabels[] = 'Ctrl+K (Cmd+K on Mac)';
}
if (!empty($shortcuts['ctrl_slash'])) {
    $enabledLabels[] = 'Ctrl+/ (Cmd+/ on Mac)';
}
if (!empty($shortcuts['custom_enabled']) && !empty($shortcuts['custom_key'])) {
    $enabledLabels[] = 'Ctrl+'.strtoupper($shortcuts['custom_key']).' (Cmd+'.strtoupper($shortcuts['custom_key']).' on Mac)';
}
$activeShortcutHelp = $enabledLabels
    ? implode(', ', $enabledLabels)
    : 'Ctrl+K (fallback)';

$accessMode = CmdPalette_normalizeAccessMode($cmdPaletteSettings['access_mode'] ?? 'permissions');
$permissionIds = CmdPalette_normalizePermissionIds($cmdPaletteSettings['permission_ids'] ?? [2]);
$allPermissions = function_exists('fetchAllPermissions') ? fetchAllPermissions() : [];
$searchPrefix = CmdPalette_normalizeSearchPrefix($cmdPaletteSettings['search_prefix'] ?? 'user:');
$paletteEnabled = !empty($cmdPaletteSettings['enabled']);
$showFooterHint = !empty($cmdPaletteSettings['show_footer_hint']);

if (!empty($errors)) {
    echo '<div class="alert alert-danger">'.implode(' ', array_map('htmlspecialchars', $errors)).'</div>';
}
if (!empty($successes)) {
    echo '<div class="alert alert-success">'.htmlspecialchars(implode(' ', $successes), ENT_QUOTES).'</div>';
}
?>
<style>
  .cp-hero { background:linear-gradient(135deg,#2ea5cb 0%,#2edcb7 100%); color:#fff; border-radius:.6rem; padding:1rem 1.25rem; margin-bottom:1rem; }
  .cp-hero h2 { color:#fff; margin:0; font-weight:600; }
  .cp-hero p { margin:.25rem 0 0; opacity:.95; }
  .cp-btn { background:linear-gradient(135deg,#2ea5cb 0%,#2edcb7 100%); border:0; color:#fff; }
  .cp-btn:hover { filter:brightness(1.07); color:#fff; }
  .cp-code { position:relative; }
  .cp-code .cp-copy { position:absolute; top:.3rem; right:.3rem; padding:.05rem .45rem; line-height:1.2; }
  .cp-perm-list { max-height:14rem; overflow:auto; border:1px solid rgba(0,0,0,.08); border-radius:.35rem; padding:.5rem .75rem; }
  .cp-perm-list label { display:block; margin-bottom:.35rem; font-weight:400; }
  .cp-access-panel[hidden] { display:none !important; }
</style>

<div class="container-fluid">
  <div class="cp-hero">
    <h2><i class="fa fa-terminal me-2"></i>Command Palette</h2>
    <p>By Envesko. Keyboard-driven command launcher for admins. Active shortcuts: <strong><?= htmlspecialchars($activeShortcutHelp, ENT_QUOTES); ?></strong></p>
  </div>

  <div class="row">
    <div class="col-12 col-lg-7 mb-4">
      <form method="POST">
        <?= tokenHere(); ?>
        <div class="card mb-3">
          <div class="card-header"><i class="fa fa-sliders me-1"></i> Behaviour</div>
          <div class="card-body">
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="enabled" id="cmdpalette-enabled" value="1"<?= $paletteEnabled ? ' checked' : ''; ?>>
              <label class="form-check-label" for="cmdpalette-enabled">Enable the command palette</label>
            </div>
            <div class="mb-3">
              <span class="form-label d-block">Who can open the palette?</span>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="access_mode" id="cmdpalette-access-master" value="master"<?= $accessMode === 'master' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="cmdpalette-access-master">Master account only</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="access_mode" id="cmdpalette-access-permissions" value="permissions"<?= $accessMode === 'permissions' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="cmdpalette-access-permissions">Users with specific permission(s)</label>
              </div>
              <div class="form-check mb-0">
                <input class="form-check-input" type="radio" name="access_mode" id="cmdpalette-access-logged-in" value="logged_in"<?= $accessMode === 'logged_in' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="cmdpalette-access-logged-in">Any logged-in user</label>
              </div>
              <div class="form-text">UserSpice permissions are explicit assignments, not ranked levels. A user must be given a permission on their account to match.</div>
            </div>
            <div class="mb-3 cp-access-panel" id="cmdpalette-perm-panel"<?= $accessMode === 'permissions' ? '' : ' hidden'; ?>>
              <label class="form-label">Allowed permissions</label>
              <?php if ($allPermissions) { ?>
                <div class="cp-perm-list">
                  <?php foreach ($allPermissions as $perm) {
                      $pid = (int) $perm->id;
                      $checked = in_array($pid, $permissionIds, true) ? ' checked' : '';
                      ?>
                  <label><input type="checkbox" name="permission_ids[]" value="<?= $pid; ?>"<?= $checked; ?>> <?= htmlspecialchars((string) $perm->name, ENT_QUOTES); ?> <span class="text-muted small">(#<?= $pid; ?>)</span></label>
                  <?php } ?>
                </div>
              <?php } else { ?>
                <p class="text-muted small mb-0">Could not load permissions from the database.</p>
              <?php } ?>
              <div class="form-text">Users with any checked permission can open the palette. Master accounts always pass permission checks in UserSpice.</div>
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="show_footer_hint" id="cmdpalette-show-footer-hint" value="1"<?= $showFooterHint ? ' checked' : ''; ?>>
              <label class="form-check-label" for="cmdpalette-show-footer-hint">Show footer hints (arrow keys, Enter, shortcut toggle)</label>
            </div>
            <div class="mb-0">
              <label for="cmdpalette-search-prefix" class="form-label">User search prefix</label>
              <input type="text" class="form-control" style="max-width:10rem;" name="search_prefix" id="cmdpalette-search-prefix" value="<?= htmlspecialchars($searchPrefix, ENT_QUOTES); ?>" placeholder="user:">
              <div class="form-text">Typed in the search box to look up users (e.g. <code><?= htmlspecialchars($searchPrefix, ENT_QUOTES); ?>alice</code>). Uses the bundled parser at <code>assets/parsers/user_search.php</code> unless your site hook overrides <code>searchUrl</code>.</div>
            </div>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><i class="fa fa-keyboard-o me-1"></i> Keyboard shortcuts</div>
          <div class="card-body">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="shortcut_ctrl_k" id="cmdpalette-shortcut-ctrl-k" value="1"<?= !empty($shortcuts['ctrl_k']) ? ' checked' : ''; ?>>
              <label class="form-check-label" for="cmdpalette-shortcut-ctrl-k">Enable <kbd>Ctrl</kbd>+<kbd>K</kbd> (<kbd>Cmd</kbd>+<kbd>K</kbd> on Mac)</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="shortcut_ctrl_slash" id="cmdpalette-shortcut-ctrl-slash" value="1"<?= !empty($shortcuts['ctrl_slash']) ? ' checked' : ''; ?>>
              <label class="form-check-label" for="cmdpalette-shortcut-ctrl-slash">Enable <kbd>Ctrl</kbd>+<kbd>/</kbd> (<kbd>Cmd</kbd>+<kbd>/</kbd> on Mac)</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="shortcut_custom_enabled" id="cmdpalette-shortcut-custom-enabled" value="1"<?= !empty($shortcuts['custom_enabled']) ? ' checked' : ''; ?>>
              <label class="form-check-label" for="cmdpalette-shortcut-custom-enabled">Enable custom shortcut</label>
            </div>
            <div class="mb-0 ms-4">
              <label for="cmdpalette-shortcut-custom-key" class="form-label small text-muted">Custom key (combined with Ctrl / Cmd)</label>
              <input type="text" class="form-control form-control-sm" style="max-width:8rem;" name="shortcut_custom_key" id="cmdpalette-shortcut-custom-key" maxlength="1" value="<?= htmlspecialchars((string) ($shortcuts['custom_key'] ?? 'p'), ENT_QUOTES); ?>" placeholder="p">
              <div class="form-text">Single letter, number, or punctuation (e.g. P, ;, ,).</div>
            </div>
          </div>
        </div>

        <button type="submit" class="btn cp-btn">Save settings</button>
      </form>
    </div>

    <div class="col-12 col-lg-5 mb-4">
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-code me-1"></i> For developers: register commands</div>
        <div class="card-body">
          <p class="mb-2">Call <code>CmdPalette_register()</code> before the page footer renders (e.g. in <code>usersc/includes/</code> or a page controller):</p>
          <div class="cp-code mb-3">
            <button type="button" class="btn btn-sm btn-light cp-copy" title="Copy" onclick="CP_copyCode('cpcode1', this)"><i class="fa fa-copy"></i></button>
<pre id="cpcode1" class="bg-light p-2 border rounded mb-0"><code>CmdPalette_register([
    'id'       => 'my-reports',
    'label'    => 'Open reports',
    'href'     => $us_url_root . 'reports/',
    'group'    => 'Navigation',
    'keywords' => ['analytics', 'stats'],
    'icon'     => 'fa-bar-chart',
]);</code></pre>
          </div>
          <p class="mb-1"><strong>Drill-in context</strong> (optional commands for the current record):</p>
          <div class="cp-code mb-3">
            <button type="button" class="btn btn-sm btn-light cp-copy" title="Copy" onclick="CP_copyCode('cpcode2', this)"><i class="fa fa-copy"></i></button>
<pre id="cpcode2" class="bg-light p-2 border rounded mb-0"><code>CmdPalette_setContext(['user' => [
    'id'       => (int) $row->id,
    'username' => $row->username,
]]);

CmdPalette_register([
    'id'    => 'ctx-billing-' . $row->id,
    'label' => 'Billing for ' . $row->username,
    'href'  => $us_url_root . 'billing/user/' . $row->id . '/',
    'group' => 'Current user',
    'icon'  => 'fa-credit-card',
]);</code></pre>
          </div>
          <p class="mb-0 text-muted small">Duplicate <code>id</code> values are ignored (first registration wins). The palette loads from the plugin footer hook.</p>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-plug me-1"></i> Site extension hook</div>
        <div class="card-body">
          <p class="mb-2">Add live search or extra boot config without editing the plugin. In <code>usersc/includes/</code>:</p>
          <div class="cp-code mb-2">
            <button type="button" class="btn btn-sm btn-light cp-copy" title="Copy" onclick="CP_copyCode('cpcode3', this)"><i class="fa fa-copy"></i></button>
<pre id="cpcode3" class="bg-light p-2 border rounded mb-0"><code>function commandpalette_site_config() {
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
}</code></pre>
          </div>
          <p class="mb-1"><strong>JS boot payload</strong> (from <code>#cmdpalette-data</code>):</p>
          <div class="cp-code mb-0">
            <button type="button" class="btn btn-sm btn-light cp-copy" title="Copy" onclick="CP_copyCode('cpcode4', this)"><i class="fa fa-copy"></i></button>
<pre id="cpcode4" class="bg-light p-2 border rounded mb-0"><code>{
  "shortcuts": { "ctrl_k": true, "ctrl_slash": true, "custom_enabled": false, "custom_key": "p" },
  "searchPrefix": "user:",
  "showFooterHint": true,
  "commands": [ { "id", "label", "href", "group", "keywords", "icon" } ],
  "context": {},
  "searchUrl": "/optional-user-search-endpoint",
  "accountManagerRoot": "/users/admin.php?view=user&id="
}</code></pre>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><i class="fa fa-info-circle me-1"></i> Notes</div>
        <div class="card-body">
          <p class="mb-2">The palette renders wherever the plugin footer loads and the viewer passes <code>CmdPalette_userCanAccess()</code> (configure access mode above).</p>
          <p class="mb-2 text-muted small">Custom themes that bypass <code>users/includes/html_footer.php</code> must call active plugin footers before <code>&lt;/body&gt;</code> (see README).</p>
          <p class="mb-0 text-muted small">User search ships with the plugin at <code>assets/parsers/user_search.php</code> (same access gate). Override <code>searchUrl</code> in <code>commandpalette_site_config()</code> only when you need custom routing or result shaping.</p>
        </div>
      </div>
    </div>
  </div>
</div>
<script nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>">
function CP_togglePermPanel() {
  var panel = document.getElementById('cmdpalette-perm-panel');
  var perms = document.getElementById('cmdpalette-access-permissions');
  if (panel && perms) {
    panel.hidden = !perms.checked;
  }
}
document.querySelectorAll('input[name="access_mode"]').forEach(function (el) {
  el.addEventListener('change', CP_togglePermPanel);
});
function CP_copyCode(id, btn) {
  var el = document.getElementById(id);
  if (!el) { return; }
  navigator.clipboard.writeText(el.innerText).then(function () {
    if (btn) {
      var html = btn.innerHTML;
      btn.innerHTML = '<i class="fa fa-check"></i>';
      setTimeout(function () { btn.innerHTML = html; }, 1200);
    }
  });
}
</script>
