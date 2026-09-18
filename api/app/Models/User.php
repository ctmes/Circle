<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Services\Authorisation\Actor;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements Actor
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    /**
     * Send the reset link to the front end rather than to the API.
     *
     * Laravel's default points at a named web route this application does not
     * have — it serves JSON and the interface is a separate Astro app. A reset
     * link that lands on an API endpoint is a link nobody can use, and this is
     * the one message whose recipient is by definition unable to work around
     * it.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = sprintf(
            '%s/reset-password?token=%s&email=%s',
            config('circle.notifications.web_url'),
            $token,
            urlencode($this->email),
        );

        \Illuminate\Support\Facades\Mail::to($this->email)->queue(
            new \App\Mail\PasswordReset($url, (int) config('auth.passwords.users.expire', 60)),
        );
    }

    // ---------------------------------------------------------- Actor

    public function actorType(): ActorType
    {
        return ActorType::User;
    }

    public function actorId(): string
    {
        return $this->id;
    }

    public function actorLabel(): string
    {
        return $this->name;
    }

    // -------------------------------------------------------- relations

    public function circleMemberships(): HasMany
    {
        return $this->hasMany(CircleMembership::class);
    }

    public function organisationMemberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    /** Circles this user can actually see — membership, never organisation. */
    public function circles()
    {
        return Circle::query()
            ->whereIn('id', $this->circleMemberships()
                ->whereNull('revoked_at')
                ->where('invite_status', 'active')
                ->select('circle_id'));
    }

    public function membershipIn(Circle $circle): ?CircleMembership
    {
        return $this->circleMemberships()->where('circle_id', $circle->id)->first();
    }
}
