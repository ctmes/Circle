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
            'id'         => $this->id,
            'name'       => $this->name,
            'purpose'    => $this->purpose,
            'status'     => $this->status->value,
            'owner'      => ['id' => $this->owner_user_id, 'name' => $this->owner?->name],
            'starts_at'  => $this->starts_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'closed_at'  => $this->closed_at?->toISOString(),
            'is_closed'  => $this->isClosed(),
            'is_expired' => $this->isExpired(),
            'created_at' => $this->created_at?->toISOString(),
            // What *this* caller may do here, so the UI never offers an action
            // the gate will refuse.
            'my_role'    => $membership?->circle_role->value,
            'my_access'  => $membership === null ? null : [
                'is_external' => $membership->is_external,
                'permissions' => array_map(
                    fn ($p) => $p->value,
                    $membership->circle_role->permissions(),
                ),
            ],
        ];
    }
}
