<?php

test('the core runtime endpoint exposes a bounded foundation snapshot', function () {
    $this->getJson('/api/runtime')
        ->assertOk()
        ->assertJsonPath('data.name', 'VSN Marketing')
        ->assertJsonStructure(['data' => ['name', 'environment', 'php', 'time']]);
});
