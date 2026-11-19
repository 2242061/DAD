<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users')->ignore($this->user()->id)],
            'current_password' => [Rule::requiredIf($this->input('email') !== $this->user()->email), 'nullable', 'string', 'current_password:web'],
            'role' => ['prohibited'],
            'blocked_at' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
        ];
    }
}
