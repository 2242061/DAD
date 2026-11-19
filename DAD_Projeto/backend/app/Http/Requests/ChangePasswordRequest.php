<?php

namespace App\Http\Requests;

use App\Rules\SafePasswordValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'max:72', 'confirmed', 'different:current_password', new SafePasswordValue, Password::min(8)->letters()->numbers()],
        ];
    }
}
