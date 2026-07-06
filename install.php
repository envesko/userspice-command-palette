<?php

require_once 'init.php';
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    $check = $db->query('SELECT * FROM us_plugins WHERE plugin = ?', [$plugin_name])->count();
    if ($check > 0) {
        err($plugin_name.' has already been installed!');
    } else {
        $fields = [
            'plugin' => $plugin_name,
            'status' => 'installed',
        ];
        $db->insert('us_plugins', $fields);
        if (!$db->error()) {
            err($plugin_name.' installed');
            logger($user->data()->id, 'USPlugins', $plugin_name.' installed');
        } else {
            err($plugin_name.' was not installed');
            logger($user->data()->id, 'USPlugins', 'Failed to install plugin, Error: '.$db->errorString());
        }
    }

    global $abs_us_root, $us_url_root;
    require_once $abs_us_root.$us_url_root.'usersc/plugins/'.$plugin_name.'/functions.php';

    if (function_exists('CmdPalette_ensureConfigTable')) {
        CmdPalette_ensureConfigTable();
    }
    if (function_exists('CmdPalette_seedSettingsIfEmpty')) {
        CmdPalette_seedSettingsIfEmpty();
    }

    $hooks = [];
    registerHooks($hooks, $plugin_name);
}
