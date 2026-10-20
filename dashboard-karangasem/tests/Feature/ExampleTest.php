<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Halaman utama kini adalah landing page, bukan dashboard publik.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('lang="id"', false)
            ->assertSee('Data pendidikan Karangasem')
            ->assertSee('Jelajahi Dashboard Publik');
    }
}
