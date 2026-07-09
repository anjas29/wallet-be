<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_currency_id' => $this->user_currency_id,
            'name' => $this->name,
            'type' => $this->type,
            'principal_amount' => $this->principal_amount,
            'interest_rate' => $this->interest_rate,
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'is_settled' => $this->is_settled,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
