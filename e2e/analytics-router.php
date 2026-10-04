<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Disposable browser fixture only. The product's purpose approval remains denied by default. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || ! (
    ($connection->getDriverName() === 'sqlite' && str_ends_with($connection->getDatabaseName(), '/e2e.sqlite'))
    || ($connection->getDriverName() === 'pgsql' && str_ends_with($connection->getDatabaseName(), '_test'))
)) {
    throw new RuntimeException('Disposable analytics E2E database required.');
}
$asset = realpath(__DIR__.'/../public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($asset !== false && str_starts_with($asset, realpath(__DIR__.'/../public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
config(['analytics.purpose_approved' => true, 'analytics.retention_days' => 30]);
$app->handleRequest(Request::capture());
