<?php

require_once 'init.php';
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    $checkQ = $db->query('SELECT * FROM us_plugins WHERE plugin = ?', [$plugin_name]);
    if ($checkQ->count() > 0) {
        $check = $checkQ->first();
        $db->update('us_plugins', $check->id, ['status' => 'active']);
        if (!$db->error()) {
            err($plugin_name.' activated');
            logger($user->data()->id, 'USPlugins', $plugin_name.' Activated');
        } else {
            err($plugin_name.' was not activated');
            logger($user->data()->id, 'USPlugins', $plugin_name.' failed to activate, Error: '.$db->errorString());
        }
    } else {
        err($plugin_name.' is not found! Has it been installed?');
        logger($user->data()->id, 'USPlugins', $plugin_name.' plugin not found - possibly not installed');
    }
}
