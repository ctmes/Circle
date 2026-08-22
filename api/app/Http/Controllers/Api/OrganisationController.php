<?php

namespace App\Http\Controllers\Api;

use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OrganisationController extends Controller
{
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
