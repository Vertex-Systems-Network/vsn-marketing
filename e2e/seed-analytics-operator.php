<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AnalyticsFixture;

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
$f = new AnalyticsFixture;
$password = 'synthetic-analytics-browser-password';
$user = User::findOrFail($f->actor->actorId);
$user->update(['password' => Hash::make($password)]);
$facts = app(AnalyticsFacts::class);
$facts->project($f->actor, $f->event());
$facts->snapshot($f->actor, new MetricDefinition('product.viewed'),
    new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now());
echo json_encode(['workspace' => $f->actor->workspaceId, 'brand' => $f->actor->brandId,
    'email' => $user->email, 'password' => $password], JSON_THROW_ON_ERROR);
