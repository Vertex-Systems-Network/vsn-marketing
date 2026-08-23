<?php

use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Events\EventsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    CoreServiceProvider::class,
    AuditServiceProvider::class,
    EventsServiceProvider::class,
    IdentityServiceProvider::class,
];
