<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Modules\Core\CoreServiceProvider::class,
    App\Modules\Audit\AuditServiceProvider::class,
    App\Modules\Events\EventsServiceProvider::class,
    App\Modules\Identity\IdentityServiceProvider::class,
];
