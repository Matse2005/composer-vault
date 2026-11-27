<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_new_users_cannot_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseCount(User::class, 0);
    }

    public function test_login_page_has_no_sign_up_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Sign up');
    }
}
