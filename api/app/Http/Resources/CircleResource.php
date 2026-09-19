<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CircleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $request->user()?->membershipIn($this->resource);

        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'purpose'         => $this->purpose,
            'status'          => $this->status->value,
            // Stated by the owner, never derived from counting objects. The
            // ring in the UI reports this figure and nothing else.
            'progress'        => (int) $this->progress,
            'progress_set_at' => $this->progress_set_at?->toISOString(),
            'owner'           => ['id' => $this->owner_user_id, 'name' => $this->owner?->name],
            'starts_at'       => $this->starts_at?->toISOString(),
            'expires_at'      => $this->expires_at?->toISOString(),
            'closed_at'       => $this->closed_at?->toISOString(),
            'is_closed'       => $this->isClosed(),
            'is_expired'      => $this->isExpired(),
            'deleted_at'      => $this->deleted_at?->toISOString(),
            'is_deleted'      => $this->isDeleted(),
            'created_at'      => $this->created_at?->toISOString(),
            // What *this* caller may do here, so the UI never offers an action
            // the gate will refuse.
            'my_role'         => $membership?->circle_role->value,
            'my_access'       => $membership === null ? null : [
                'is_external' => $membership->is_external,
                // The company this caller sits in. Cross-company screens need
                // to say "this is waiting on you" rather than naming a party
                // and leaving the reader to work out whether that means them.
                'party'       => $membership->party === null ? null : [
                    'id'    => $membership->party->id,
                    'label' => $membership->party->label(),
                ],
                'permissions' => $this->effectivePermissions($membership),
            ],
        ];
    }

    /**
     * What this person may actually do, role defaults *plus* their grants.
     *
     * Previously the role alone. That was correct while nothing could write a
     * grant; now that they can be written, reporting only the role means a
     * person handed `agent.author` sees no button for it — the gate would allow
     * the action and the UI would never offer it, which reads as the grant not
     * having worked.
     *
     * @return list<string>
     */
    private function effectivePermissions(\App\Models\CircleMembership $membership): array
    {
        $permissions = collect($membership->circle_role->permissions())
            ->map(fn (\App\Enums\Permission $p) => $p->value);

        $grants = \App\Models\RoleGrant::where('circle_id', $this->id)
            ->where('user_id', $membership->user_id)
            ->get()
            ->filter(fn (\App\Models\RoleGrant $g) => $g->isActive());

        return $permissions
            ->merge($grants->where('allow', true)->map(fn ($g) => $g->permission->value))
            ->reject(fn (string $p) => $grants->where('allow', false)
                ->contains(fn ($g) => $g->permission->value === $p))
            ->unique()
            ->values()
            ->all();
    }
}
