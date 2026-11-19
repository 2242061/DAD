<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalletOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $reference = match ($type) {
            'MBWAY' => ['regex:/\A9[0-9]{8}\z/'],
            'PAYPAL' => ['email:rfc'],
            'IBAN' => ['regex:/\A[A-Z]{2}[0-9]{23}\z/'],
            'MB' => ['regex:/\A[0-9]{5}-[0-9]{9}\z/'],
            'VISA' => ['regex:/\A4[0-9]{15}\z/'],
            default => [],
        };

        return [
            'idempotency_key' => ['required', 'uuid'],
            'type' => ['required', Rule::in(['MBWAY', 'PAYPAL', 'IBAN', 'MB', 'VISA'])],
            'reference' => ['required', 'string', 'max:254', ...$reference],
            'value' => ['required', 'integer', 'between:1,99'],
            'current_password' => $this->routeIs('wallet.withdraw') ? ['required', 'string', 'current_password:web'] : ['sometimes', 'string'],
            'balance' => 'prohibited', 'fee_coins' => 'prohibited', 'exchange_rate' => 'prohibited', 'status' => 'prohibited', 'user_id' => 'prohibited',
        ];
    }
}
