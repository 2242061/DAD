<?php

return [
    'required' => 'Preenche o campo :attribute.',
    'string' => 'O campo :attribute deve ser um texto.',
    'email' => 'Indica um email válido.',
    'unique' => 'Este email já está associado a uma conta.',
    'confirmed' => 'As palavras-passe não coincidem.',
    'current_password' => 'A palavra-passe atual está incorreta.',
    'different' => 'Escolhe uma palavra-passe diferente da atual.',
    'in' => 'O valor do campo :attribute não é válido.',
    'enum' => 'Seleciona um tipo de conta válido.',
    'prohibited' => 'Não podes alterar o campo :attribute.',
    'min' => ['string' => 'O campo :attribute deve ter pelo menos :min caracteres.'],
    'max' => ['string' => 'O campo :attribute pode ter no máximo :max caracteres.'],
    'password' => [
        'letters' => 'Inclui pelo menos uma letra na palavra-passe.',
        'numbers' => 'Inclui pelo menos um número na palavra-passe.',
    ],
    'attributes' => [
        'name' => 'nome', 'email' => 'email', 'password' => 'palavra-passe',
        'current_password' => 'palavra-passe atual', 'reason' => 'motivo', 'confirmation' => 'confirmação',
    ],
];
