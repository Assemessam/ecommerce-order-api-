<?php

it('reports that the API is healthy', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'status' => 'ok',
            ],
        ]);
});
