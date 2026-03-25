<?php

declare(strict_types=1);

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_readable($autoloadPath)) {
    fwrite(STDERR, "vendor/autoload.php fehlt. Bitte zuerst `composer install` im Plugin-Ordner ausführen.\n");
    exit(1);
}

require_once $autoloadPath;

$pluginClassAvailable = class_exists(\ThothWordPressPlugin\Plugin::class);
$thothClientAvailable = class_exists(\ThothApi\GraphQL\Client::class);

if (!$pluginClassAvailable || !$thothClientAvailable) {
    fwrite(STDERR, "Smoke-Test fehlgeschlagen: Klassen konnten nicht geladen werden.\n");
    exit(1);
}

echo "Smoke-Test ok: Plugin- und Thoth-Client-Klassen sind verfügbar.\n";
