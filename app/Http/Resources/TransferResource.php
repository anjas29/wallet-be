<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id,
            'from_amount' => $this->from_amount,
            'to_amount' => $this->to_amount,
            'exchange_rate' => $this->exchange_rate,
            'fee' => $this->fee,
            'description' => $this->description,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
