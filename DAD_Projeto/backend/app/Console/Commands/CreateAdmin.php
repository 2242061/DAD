<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\SafePasswordValue;
use App\Services\AccountSecurity;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email : Email do novo administrador} {--name= : Nome do administrador}';

    protected $description = 'Cria uma nova conta de administrador através do terminal';

    public function handle(AccountSecurity $security): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $name = $this->option('name') ?: $this->ask('Nome do administrador');
        $password = $this->secret('Palavra-passe (mínimo 8 caracteres, com letras e números)');
        $confirmation = $this->secret('Repete a palavra-passe');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'max:72', 'confirmed', new SafePasswordValue, Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User($validator->safe()->only(['name', 'email', 'password']));
        $user->role = UserRole::Admin;
        $user->save();
        $security->record(null, $user, 'user.admin_created');

        $this->info('Administrador criado. Já podes entrar na plataforma com este email.');

        return self::SUCCESS;
    }
}
