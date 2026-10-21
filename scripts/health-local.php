<?php
declare(strict_types=1);

// Readiness before and after install: core copying complete, DB usable, Apache ready.
try {
    foreach (['wp-config.php', 'wp-includes/compat.php', 'wp-includes/version.php', 'wp-admin/install.php'] as $file) {
        if (!is_readable('/var/www/html/' . $file)) {
            throw new RuntimeException('Core copy not complete.');
        }
    }
    $db = new mysqli('database', (string) getenv('WORDPRESS_DB_USER'), (string) getenv('WORDPRESS_DB_PASSWORD'), (string) getenv('WORDPRESS_DB_NAME'), 3306);
    $db->query('SELECT 1');
    $request = curl_init('http://127.0.0.1/wp-login.php');
    curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $body = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    if ($body === false || !in_array($status, [200, 302], true)) {
        throw new RuntimeException('WordPress HTTP not ready.');
    }
    echo "core-db-http-ready\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Local WordPress not ready.\n");
    exit(1);
}
