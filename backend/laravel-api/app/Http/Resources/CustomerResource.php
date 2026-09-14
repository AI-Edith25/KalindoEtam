<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'address' => $this->address,
            'no_ktp' => $this->no_ktp,
            'no_npwp' => $this->no_npwp,
            'area' => $this->area,
            'sales_person_id' => $this->sales_person_id,
            'sales_person' => $this->whenLoaded('salesPerson', fn () => [
                'id' => $this->salesPerson->id,
                'code' => $this->salesPerson->code,
                'name' => $this->salesPerson->name,
            ]),
            'credit_limit' => $this->credit_limit,
            'terms_of_payment_id' => $this->terms_of_payment_id,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
