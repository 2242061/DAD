<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users')->ignore($this->route('user')->id)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'blocked_at' => ['prohibited'],
        ];
    }
}
