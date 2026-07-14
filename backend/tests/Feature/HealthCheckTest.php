<?php

it('returns backend health', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => 'hivefinance-backend',
        ]);
});
