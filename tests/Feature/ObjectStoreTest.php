<?php

use App\Modules\Core\Domain\Contracts\ObjectStore;
use Illuminate\Support\Facades\Storage;

it('uses the filesystem abstraction instead of a provider SDK', function () {
    Storage::fake('local');
    config()->set('infrastructure.object_store.disk', 'local');

    $store = app(ObjectStore::class);

    $store->put('contracts/example.txt', 'provider neutral');

    expect($store->exists('contracts/example.txt'))->toBeTrue()
        ->and($store->get('contracts/example.txt'))->toBe('provider neutral');

    $store->delete('contracts/example.txt');

    expect($store->exists('contracts/example.txt'))->toBeFalse();
});
