<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug'];

    public function circles(): HasMany
    {
        return $this->hasMany(Circle::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    public function workPackages(): HasMany
    {
        return $this->hasMany(WorkPackage::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(WorkApplication::class);
    }

    /**
     * The organisations this one has been engaged with (spec §21.5).
     *
     * Two queries rather than one, because the pair is stored in a fixed order
     * so a relationship is one row — which means "everyone I have worked with"
     * lives on both sides of the pair and neither side can be dropped.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function networkIds(): \Illuminate\Support\Collection
    {
        $asA = OrganisationRelationship::where('organisation_a_id', $this->id)->pluck('organisation_b_id');
        $asB = OrganisationRelationship::where('organisation_b_id', $this->id)->pluck('organisation_a_id');

        return $asA->concat($asB)->unique()->values();
    }
}
