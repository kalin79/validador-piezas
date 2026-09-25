<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // La raiz redirige al panel; lo que se verifica aqui es que la
        // aplicacion arranca, con la ruta de salud de Laravel.
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
