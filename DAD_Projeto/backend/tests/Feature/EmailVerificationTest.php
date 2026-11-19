<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        Notification::fake();
    }

    private function link(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
    }

    public function test_registration_sends_a_verification_without_marking_the_email_verified(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'Maria', 'email' => 'maria@example.test', 'password' => 'Damas2026!', 'password_confirmation' => 'Damas2026!'])
            ->assertCreated()->assertJsonPath('data.email_verified', false);
        Notification::assertSentTo(User::sole(), VerifyEmail::class);
    }

    public function test_a_signed_link_verifies_only_the_authenticated_owner(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->link($user);
        $this->getJson($url)->assertUnauthorized();
        $other = User::factory()->create();
        $this->actingAs($other, 'web')->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->getJson($url)->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'email.verified', 'target_id' => $user->id]);
    }

    public function test_expired_tampered_and_previous_email_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $valid = $this->link($user);
        $this->actingAs($user, 'web')->getJson($this->link($user, -1))->assertForbidden();
        $this->getJson($valid.'tampered')->assertForbidden();
        $user->email = 'changed@example.test';
        $user->save();
        $this->getJson($valid)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_confirmation_can_be_resent_and_is_rate_limited(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user, 'web');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/email/resend')->assertOk();
        }
        $this->postJson('/api/auth/email/resend')->assertTooManyRequests();
        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
    }
}
