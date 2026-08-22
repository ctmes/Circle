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
