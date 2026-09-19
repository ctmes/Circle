<?php

namespace App\Http\Controllers\Api;

use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tokens a note-taker uses to send transcripts in (spec §24).
 *
 * A connector token is a Sanctum personal access token with exactly two
 * abilities: `transcripts:ingest`, and `org:<id>` naming the one company it may
 * send to. ConfineScopedTokens refuses it everywhere except the ingest route,
 * and TranscriptController takes the company from the token rather than from
 * the request body, so a token pasted into somebody else's automation can post
 * a transcript to one company and do nothing else at all.
 *
 * It acts as the person who created it — transcripts it sends run on their
 * authority and the import log names them — which is why only a member of the
 * company can create one, and why each person sees and revokes only their own.
 *
 * The plain token is returned once, at creation. It is not stored in a form
 * that could be shown again.
 */
class ConnectorTokenController extends Controller
{
    private const PREFIX = 'connector:';

    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->where('name', 'like', self::PREFIX . '%')
            ->latest()
            ->get()
            ->map(fn (PersonalAccessToken $t) => $this->present($t));

        return response()->json(['data' => $tokens->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organisation_id' => ['required', 'string', 'exists:organisations,id'],
            'name'            => ['required', 'string', 'max:80'],
        ]);

        $organisation = Organisation::findOrFail($data['organisation_id']);

        abort_unless(
            $organisation->memberships()->where('user_id', $request->user()->id)->exists(),
            403,
            'You are not a member of this company.',
        );

        $token = $request->user()->createToken(
            self::PREFIX . trim($data['name']),
            ['transcripts:ingest', 'org:' . $organisation->id],
        );

        return response()->json([
            'data' => array_merge($this->present($token->accessToken), [
                // Shown once. Nothing can retrieve it again.
                'token' => $token->plainTextToken,
            ]),
        ], 201);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $row = $request->user()->tokens()
            ->where('name', 'like', self::PREFIX . '%')
            ->whereKey($token)
            ->firstOrFail();

        $row->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(PersonalAccessToken $token): array
    {
        $organisationId = null;

        foreach ((array) $token->abilities as $ability) {
            if (str_starts_with((string) $ability, 'org:')) {
                $organisationId = substr((string) $ability, 4);
            }
        }

        return [
            'id'              => $token->id,
            'name'            => substr($token->name, strlen(self::PREFIX)),
            'organisation_id' => $organisationId,
            'last_used_at'    => $token->last_used_at?->toISOString(),
            'created_at'      => $token->created_at?->toISOString(),
        ];
    }
}
