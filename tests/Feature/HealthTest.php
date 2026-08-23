<?php

test('the application health endpoint is available', function () {
    $this->get('/up')->assertOk();
});
