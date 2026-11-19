<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('wallet-operations', fn (Request $request) => [Limit::perMinute(10)->by('wallet-user:'.$request->user()->id), Limit::perMinute(30)->by('wallet-ip:'.$request->ip())]);
        RateLimiter::for('online', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('online-create', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('online-join', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        VerifyEmail::createUrlUsing(function ($user): string {
            $path = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                'id' => $user->id, 'hash' => sha1($user->getEmailForVerification()),
            ], absolute: false);

            return rtrim(config('app.frontend_url'), '/').'/#/verificar-email?'.http_build_query(['path' => $path]);
        });
        VerifyEmail::toMailUsing(fn ($user, string $url) => (new MailMessage)
            ->subject('Confirma o teu email · Dama')->greeting('Olá, '.$user->name.'!')
            ->line('Confirma este endereço para concluir a configuração da tua conta.')
            ->action('Confirmar email', $url)->line('Este link é válido durante 60 minutos.')
            ->line('Se não criaste esta conta, podes ignorar esta mensagem.')->salutation('O clube Dama'));

        ResetPassword::createUrlUsing(fn ($user, string $token): string => rtrim(config('app.frontend_url'), '/').'/#/repor-palavra-passe?'.http_build_query([
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ]));
        ResetPassword::toMailUsing(function ($user, string $token): MailMessage {
            $url = rtrim(config('app.frontend_url'), '/').'/#/repor-palavra-passe?'.http_build_query([
                'token' => $token, 'email' => $user->getEmailForPasswordReset(),
            ]);

            return (new MailMessage)->subject('Recuperar a tua conta · Dama')->greeting('Olá!')
                ->line('Recebemos um pedido para alterar a palavra-passe da tua conta.')
                ->action('Escolher nova palavra-passe', $url)->line('Este link é válido durante 60 minutos e só pode ser utilizado uma vez.')
                ->line('Se não fizeste este pedido, podes ignorar esta mensagem.')->salutation('O clube Dama');
        });

        $tooManyAttempts = fn (Request $request, array $headers) => response()->json([
            'message' => 'Demasiadas tentativas. Aguarda um minuto e tenta novamente.',
        ], 429, $headers);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip())->response($tooManyAttempts),
            Limit::perMinute(5)
                ->by(mb_strtolower(trim(is_string($request->input('email')) ? $request->input('email') : '')).'|'.$request->ip())
                ->response($tooManyAttempts),
        ]);

        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip())->response($tooManyAttempts)
        );
        RateLimiter::for('recovery', fn (Request $request) => Limit::perMinute(5)->by($request->ip())->response($tooManyAttempts));
        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(3)->by($request->user()->id)->response($tooManyAttempts));
        RateLimiter::for('account-changes', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id)->response($tooManyAttempts));
    }
}
