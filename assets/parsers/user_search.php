<?php
/**
 * AJAX user lookup for the command palette (username, email, exact numeric id).
 *
 * Gate: logged in, CmdPalette permission, CSRF token on GET.
 */
require_once dirname(__DIR__, 5).'/users/init.php';

header('Content-Type: application/json');

$fail = static function ($code = 403) {
    http_response_code($code);
    echo json_encode(['state' => false, 'results' => []]);
    exit;
};

if (!function_exists('CmdPalette_userCanAccess') || !CmdPalette_userCanAccess()) {
    $fail();
}
if (!Token::check(Input::get('token'))) {
    $fail();
}

$q = trim((string) Input::get('q'));
if (mb_strlen($q) < 2) {
    echo json_encode(['state' => true, 'results' => []]);
    exit;
}

$sanitized = Input::sanitize($q);
$like = '%'.$sanitized.'%';
$params = [$like, $like];
$where = 'WHERE (u.username LIKE ? OR u.email LIKE ?';

if (ctype_digit($q)) {
    $where .= ' OR u.id = ?';
    $params[] = (int) $q;
}
$where .= ')';

$sql = 'SELECT u.id, u.username, u.email
        FROM users u
        '.$where.'
        ORDER BY u.username ASC
        LIMIT 10';

$rows = $db->query($sql, $params);
if ($db->error()) {
    $fail(500);
}

$results = [];
foreach ($rows->results() as $row) {
    $username = (string) $row->username;
    $email = (string) ($row->email ?? '');
    $labelEmail = $email;
    if (mb_strlen($labelEmail) > 24) {
        $labelEmail = mb_substr($labelEmail, 0, 21).'…';
    }

    $results[] = [
        'id' => (int) $row->id,
        'username' => $username,
        'label' => $username.' · #'.$row->id.' · '.$labelEmail,
    ];
}

echo json_encode(['state' => true, 'results' => $results]);
