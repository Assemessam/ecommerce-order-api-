<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** @format email */
            'email' => $this->email,
            /** @format date-time */
            'created_at' => $this->created_at?->toISOString(),
            /** @format date-time */
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
