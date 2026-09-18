<?php

namespace Tests\Feature;

use App\Mail\PasswordReset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Getting back in.
 *
 * There was no way to. Every other gap in this product degrades the record;
 * this one locks somebody out of it entirely, and it is the first thing that
 * happens to an external collaborator who signs in once a month.
 */
class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeUser(): User
    {
        return User::create([
            'name'     => 'Jules Ba',
            'email'    => 'jules@geotech.test',
            'password' => 'the-original-password',
        ]);
    }

    public function test_a_reset_link_goes_to_the_front_end_not_the_api(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/forgot-password', ['email' => 'jules@geotech.test'])
            ->assertOk();

        Mail::assertQueued(PasswordReset::class, function (PasswordReset $mail) {
            $this->assertStringContainsString('/reset-password?token=', $mail->resetUrl);
            $this->assertStringNotContainsString('/api/', $mail->resetUrl);

            return $mail->hasTo('jules@geotech.test');
        });
    }

    public function test_an_unknown_address_is_answered_exactly_like_a_known_one(): void
    {
        $this->makeUser();

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'jules@geotech.test'])->assertOk();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@nowhere.test'])->assertOk();

        // Otherwise this form is a way to test whether somebody's client is on
        // the platform, which is the same disclosure login already refuses.
        $this->assertSame($known->json('message'), $unknown->json('message'));

        Mail::assertQueued(PasswordReset::class, 1);
    }

    public function test_resetting_signs_you_in_and_ends_every_other_session(): void
    {
        $user = $this->makeUser();

        // A session somebody else may be holding.
        $stolen = $user->createToken('api')->plainTextToken;
        $this->assertSame(1, $user->tokens()->count());

        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'jules@geotech.test',
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertNotNull($response->json('token'));

        // The old bearer token is gone. Somebody resetting a password is either
        // locked out or compromised, and in the second case leaving the
        // attacker's token alive would make the reset ceremonial.
        $this->withHeader('Authorization', 'Bearer ' . $stolen)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        // And the new password works.
        $this->postJson('/api/auth/login', [
            'email'    => 'jules@geotech.test',
            'password' => 'a-brand-new-password',
        ])->assertOk();
    }

    public function test_a_used_or_forged_token_is_refused(): void
    {
        $user  = $this->makeUser();
        $token = Password::createToken($user);

        $payload = [
            'token'                 => $token,
            'email'                 => 'jules@geotech.test',
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        // Once.
        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);

        $this->postJson('/api/auth/reset-password', array_merge($payload, ['token' => 'invented']))
            ->assertStatus(422);
    }

    public function test_a_short_password_is_refused(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/reset-password', [
            'token'                 => Password::createToken($user),
            'email'                 => 'jules@geotech.test',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function test_signing_in_is_limited_but_generously(): void
    {
        // A login endpoint with no limit is a password list with a slow
        // interface. The limit still has to clear a whole team arriving at nine
        // o'clock behind one office address, so it sits at twenty a minute.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'jules@geotech.test', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'jules@geotech.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_asking_for_reset_links_is_limited_hard(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'jules@geotech.test'])->assertOk();
        }

        // The one endpoint that makes the server send mail to an address the
        // caller chose. Unlimited, it buries somebody's inbox and burns the
        // sending reputation the invitations depend on.
        $this->postJson('/api/auth/forgot-password', ['email' => 'jules@geotech.test'])
            ->assertStatus(429);
    }
}
