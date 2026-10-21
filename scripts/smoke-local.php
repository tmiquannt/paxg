<?php
declare(strict_types=1);

// Local HTTP login test; creates one WP session and logs it out, no business data.
$request = curl_init();
curl_setopt_array($request, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_COOKIEFILE => '', // In-memory cookie engine; no credentials written to disk.
    CURLOPT_CONNECT_TO => ['127.0.0.1:18088:wordpress:80'],
]);
$base = 'http://127.0.0.1:18088';
$fetch = static function (string $path, ?array $data = null) use ($request, $base): array {
    curl_setopt($request, CURLOPT_URL, $base . $path);
    curl_setopt($request, CURLOPT_POST, $data !== null);
    if ($data !== null) {
        curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $body = curl_exec($request);
    if ($body === false) {
        throw new RuntimeException(curl_error($request));
    }
    return [curl_getinfo($request, CURLINFO_RESPONSE_CODE), $body];
};
[$status, $body] = $fetch('/wp-login.php');
if ($status !== 200 || !str_contains($body, 'user_login')) {
    throw new RuntimeException('Login page failed.');
}
[$status] = $fetch('/wp-login.php', [
    'log' => 'erp_local_admin',
    'pwd' => (string) getenv('ERP_LOCAL_ADMIN_PASSWORD'),
    'wp-submit' => 'Log In',
    'redirect_to' => $base . '/wp-admin/',
    'testcookie' => '1',
]);
if ($status !== 302) {
    throw new RuntimeException('Local authentication failed.');
}
[$status, $body] = $fetch('/wp-admin/');
if ($status !== 200 || !str_contains($body, 'wpadminbar') || !str_contains($body, 'erp_local_admin')) {
    throw new RuntimeException('Authenticated dashboard failed.');
}
if (!preg_match("~href=['\"]([^'\"]*wp-login\\.php\\?action=logout[^'\"]*)['\"]~", $body, $match)) {
    throw new RuntimeException('Logout link absent.');
}
$logout = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
if (!str_starts_with($logout, $base . '/')) {
    throw new RuntimeException('Refusing a non-local logout URL.');
}
$fetch(substr($logout, strlen($base)));
[$status] = $fetch('/wp-admin/');
if ($status !== 302) {
    throw new RuntimeException('Session logout failed.');
}
echo "PASS: real HTTP login form, authenticated dashboard, logout; no ERP workflow claimed.\n";
