<?php

require_once 'init.php';
// Called only when the plugin is actively DELETED (not on deactivate).
if (in_array($user->data()->id, $master_account)) {
    include 'plugin_info.php';

    global $abs_us_root, $us_url_root;

    $db = DB::getInstance();
    try {
        $db->query('DROP TABLE IF EXISTS commandpalette_config');
    } catch (Exception $e) {
        // fail silent on delete
    }


    logger($user->data()->id, 'USPlugins', $plugin_name.' deleted (config removed)');
}
