<?php

it('returns backend health', function (): void {
    $this->getJson('/v1/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => 'hivefinance-backend',
        ]);
});
