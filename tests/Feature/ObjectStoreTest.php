<?php

use App\Modules\Core\Domain\Contracts\ObjectStore;
use Illuminate\Support\Facades\Storage;

it('uses the configured Laravel filesystem disk behind the object-store contract', function () {
    Storage::fake('local');
    config()->set('infrastructure.object_store.disk', 'local');
    app()->forgetInstance(ObjectStore::class);

    $store = app(ObjectStore::class);
    $store->put('contracts/object-store.txt', 'vsn');

    expect($store->exists('contracts/object-store.txt'))->toBeTrue()
        ->and($store->get('contracts/object-store.txt'))->toBe('vsn');

    $store->delete('contracts/object-store.txt');
    expect($store->exists('contracts/object-store.txt'))->toBeFalse();
});
