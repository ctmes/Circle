<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A connector token reaches the one endpoint it was issued for (spec §24).
 *
 * A token pasted into Zapier, Make or a note-taker's webhook settings lives in
 * somebody else's system from then on — in their logs, their support tooling,
 * their breach. Sanctum's abilities are only enforced where a route asks for
 * one, and none of this API's routes did, because until now every token was a
 * person's own session. Without this, the token that lets Granola post a
 * transcript would also read every Circle its owner can, invite people, and
 * approve an agent's actions.
 *
 * So the rule is inverted for connector tokens: refused everywhere except the
 * routes named for their scope. The tokens people sign in with carry `*` and
 * are untouched.
 */
class ConfineScopedTokens
{
    /**
     * Route names a transcript connector token may reach, and nothing else.
     *
     * Write-only on purpose. Letting the token read an import back would let
     * whoever holds it — the note-taker, the automation service, whoever
     * breaches either — read what every meeting changed. The 202 it gets on
     * submission carries the import id; a person reads the outcome in Circle.
     */
    private const INGEST_ROUTES = ['transcripts.ingest'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isConnectorToken($request->user()?->currentAccessToken())) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::INGEST_ROUTES, true)) {
            return $next($request);
        }

        abort(403, 'This token can only submit meeting transcripts.');
    }

    /**
     * Whether a token is a transcript connector rather than somebody's session.
     *
     * Identified by what it carries — the ingest ability and not the wildcard —
     * rather than by what it lacks. "Anything without `*`" was the first
     * version of this rule, and it caught every token that simply declared no
     * abilities at all, which is not a scope anyone issued. The only narrowed
     * tokens this application mints are connector tokens, and this names them.
     */
    public static function isConnectorToken(mixed $token): bool
    {
        return $token !== null
            && method_exists($token, 'can')
            && $token->can('transcripts:ingest')
            && ! $token->can('*');
    }
}
