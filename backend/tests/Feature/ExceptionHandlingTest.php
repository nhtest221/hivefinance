<?php

use Illuminate\Support\Facades\Route;

it('returns the generic internal_error envelope for an unexpected exception without leaking internals', function (): void {
    Route::get('/v1/__test/throws', function (): never {
        throw new RuntimeException('a secret internal detail that must never reach the client');
    })->middleware('api');

    $response = $this->getJson('/v1/__test/throws');

    $response->assertStatus(500)
        ->assertJsonPath('error_code', 'internal_error')
        ->assertJsonPath('message', 'An unexpected error occurred.')
        ->assertJsonPath('details', []);

    expect($response->getContent())->not->toContain('a secret internal detail');
});

it('still returns a framework 404 for a genuinely unmatched route, not the catch-all 500', function (): void {
    $this->getJson('/v1/this-route-does-not-exist')->assertStatus(404);
});
