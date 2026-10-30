<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushSession();
        config(['agenthost.password_hash' => Hash::make('correct horse battery')]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/state')->assertUnauthorized();
        $this->postJson('/api/sessions', ['title' => 'x', 'agent' => 'claude', 'workdir' => '/tmp'])->assertUnauthorized();
    }

    public function test_correct_password_signs_in(): void
    {
        $this->post('/login', ['password' => 'correct horse battery'])->assertRedirect('/');

        $this->assertTrue(session('agent_host.authenticated'));
        $this->get('/login')->assertRedirect('/');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->from('/login')->post('/login', ['password' => 'nope'])->assertRedirect('/login')->assertSessionHasErrors('password');

        $this->getJson('/api/state')->assertUnauthorized();
    }

    public function test_login_fails_closed_without_a_configured_password(): void
    {
        config(['agenthost.password_hash' => '']);

        $this->from('/login')->post('/login', ['password' => ''])->assertSessionHasErrors('password');
        $this->from('/login')->post('/login', ['password' => 'anything'])->assertSessionHasErrors('password');
        $this->getJson('/api/state')->assertUnauthorized();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->withSession(['agent_host.authenticated' => true])->postJson('/logout')->assertNoContent();

        $this->getJson('/api/state')->assertUnauthorized();
    }

    public function test_user_can_sign_up_with_name_email_phone_and_password_and_login_by_username_or_email(): void
    {
        $this->post('/register', [
            'name' => 'bryan_kun',
            'email' => 'bryan@sembakobayi.com',
            'phone' => '+628123456789',
            'password' => 'secret1234',
        ])->assertRedirect('/');

        $this->assertTrue(session('agent_host.authenticated'));
        $this->assertSame('bryan@sembakobayi.com', session('agent_host.user.email'));

        // Log out
        $this->postJson('/logout')->assertNoContent();

        // Log in using username + password
        $this->post('/login', [
            'login' => 'bryan_kun',
            'password' => 'secret1234',
        ])->assertRedirect('/');
        $this->assertTrue(session('agent_host.authenticated'));

        // Log out and log in using email + password
        $this->postJson('/logout')->assertNoContent();
        $this->post('/login', [
            'login' => 'bryan@sembakobayi.com',
            'password' => 'secret1234',
        ])->assertRedirect('/');
        $this->assertTrue(session('agent_host.authenticated'));
    }
}
