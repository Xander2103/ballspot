<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class XpEventResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'amount'      => $this->amount,
            'reason'      => $this->reason,
            'source_type' => $this->source_type,
            'metadata'    => $this->metadata,
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
