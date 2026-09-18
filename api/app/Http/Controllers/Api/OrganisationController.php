<?php

namespace App\Http\Controllers\Api;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganisationController extends Controller
{
    /**
     * Create an organisation and put the caller in it.
     *
     * Until this existed, an organisation could only be created by running
     * `circle:provision-org` on a server, which meant no counterparty could be
     * brought on board without the operator of the deployment doing it by hand.
     * Every cross-company workflow in the product depends on the other company
     * existing, so that command was the first step of everything and it was not
     * reachable from the product.
     *
     * Creating an organisation grants nothing beyond the ability to open a
     * Circle under it. Organisation membership conveys no Circle access (spec
     * §2) and is never consulted by AccessGate, so this endpoint cannot widen
     * anybody's reach into work that already exists.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
        ]);

        $organisation = DB::transaction(function () use ($data, $request) {
            $organisation = Organisation::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
            ]);

            // The creator is an owner rather than a member. Nothing reads
            // org_role yet, and writing "member" here would mean that when
            // something does, the person who created the organisation is
            // indistinguishable from someone who was added to it.
            OrganisationMembership::create([
                'organisation_id' => $organisation->id,
                'user_id'         => $request->user()->id,
                'org_role'        => 'owner',
                'is_external'     => false,
            ]);

            return $organisation;
        });

        return response()->json([
            'data' => ['id' => $organisation->id, 'name' => $organisation->name, 'slug' => $organisation->slug],
        ], 201);
    }

    /**
     * A slug nobody is already using.
     *
     * Two organisations may legitimately share a name — there is no registry of
     * company names here and no verification of who anyone is (spec §21.7), so
     * refusing the second one would be enforcing a uniqueness the product
     * cannot actually check. The suffix keeps the URL distinct instead.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organisation';
        $slug = $base;

        while (Organisation::where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }

    /**
     * Organisations the caller belongs to.
     *
     * This exists so a Circle can be opened without anyone hand-copying a ULID.
     * It deliberately exposes nothing about the organisation's Circles —
     * organisation membership grants no Circle access (spec §2), and this
     * endpoint must not become a way to enumerate missions.
     */
    public function index(Request $request): JsonResponse
    {
        $organisations = Organisation::query()
            ->whereIn('id', $request->user()->organisationMemberships()->select('organisation_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json(['data' => $organisations]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $organisation = Organisation::where('slug', $slug)->firstOrFail();

        abort_unless(
            $organisation->memberships()->where('user_id', $request->user()->id)->exists(),
            403,
            'You are not a member of this organisation.',
        );

        return response()->json([
            'data' => [
                'id'      => $organisation->id,
                'name'    => $organisation->name,
                'slug'    => $organisation->slug,
                // Only the caller's own Circles within this organisation.
                'circles' => $request->user()->circles()
                    ->where('organisation_id', $organisation->id)
                    ->orderByDesc('created_at')
                    ->get(['id', 'name', 'purpose', 'status', 'expires_at', 'closed_at']),
            ],
        ]);
    }
}
