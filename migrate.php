<?php
// Numbered migrations, applied after a Spice Shaker update (and on activation).
// Applied state is tracked in us_plugins.updates so each runs once.

include 'plugin_info.php';
if (in_array($user->data()->id, $master_account) && pluginActive($plugin_name, true)) {
    $count = 0;
    $db = DB::getInstance();

    global $abs_us_root, $us_url_root;
    require_once $abs_us_root.$us_url_root.'usersc/plugins/'.$plugin_name.'/functions.php';

    $checkQ = $db->query('SELECT id, updates FROM us_plugins WHERE plugin = ?', [$plugin_name]);
    if ($checkQ->count() > 0) {
        $check = $checkQ->first();
        $existing = ($check->updates == '') ? [] : json_decode($check->updates);

        if (!in_array(1, $existing)) {
            if (function_exists('CmdPalette_ensureConfigTable')) {
                CmdPalette_ensureConfigTable();
            }
            if (function_exists('CmdPalette_seedSettingsIfEmpty')) {
                CmdPalette_seedSettingsIfEmpty();
            }
            $existing[] = 1;
            ++$count;
        }

        $new = json_encode($existing);
        $db->update('us_plugins', $check->id, ['updates' => $new, 'last_check' => date('Y-m-d H:i:s')]);
        if (!$db->error()) {
            logger($user->data()->id, 'Migrations', "$count migration(s) triggered for $plugin_name");
        } else {
            logger($user->data()->id, 'USPlugins', 'Failed to save updates, Error: '.$db->errorString());
        }
    }
}
