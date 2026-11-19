<?php

namespace App\Http\Requests;

use App\Rules\SafePasswordValue;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:72', new SafePasswordValue],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Indica o teu email.',
            'email.email' => 'Indica um email válido.',
            'email.max' => 'O email pode ter no máximo 254 caracteres.',
            'password.required' => 'Indica a tua palavra-passe.',
            'password.max' => 'A palavra-passe pode ter no máximo 72 caracteres.',
        ];
    }
}
