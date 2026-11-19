<?php

namespace App\Http\Requests;

use App\Rules\SafePasswordValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'max:72', 'confirmed', new SafePasswordValue, Password::min(8)->letters()->numbers()],
            'role' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Indica o teu nome.',
            'name.string' => 'O nome deve ser um texto.',
            'name.min' => 'O nome deve ter pelo menos 2 caracteres.',
            'name.max' => 'O nome pode ter no máximo 80 caracteres.',
            'email.required' => 'Indica o teu email.',
            'email.email' => 'Indica um email válido.',
            'email.unique' => 'Este email já está associado a uma conta.',
            'email.max' => 'O email pode ter no máximo 254 caracteres.',
            'password.required' => 'Escolhe uma palavra-passe.',
            'password.confirmed' => 'As palavras-passe não coincidem.',
            'password.max' => 'A palavra-passe pode ter no máximo 72 caracteres.',
            'password.min' => 'Usa pelo menos 8 caracteres.',
            'password.letters' => 'Inclui pelo menos uma letra.',
            'password.numbers' => 'Inclui pelo menos um número.',
            'role.prohibited' => 'O tipo de conta é definido pela plataforma.',
        ];
    }
}
