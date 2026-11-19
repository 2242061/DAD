<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ShowLocalMail extends Command
{
    protected $signature = 'app:mail-links {--limit=5 : Número de links recentes a mostrar}';

    protected $description = 'Mostra links de email gerados localmente, sem expor uma caixa de correio na API';

    public function handle(): int
    {
        if (! app()->environment('local') || config('mail.default') !== 'log') {
            $this->error('Este comando só está disponível em ambiente local com MAIL_MAILER=log.');

            return self::FAILURE;
        }
        $path = storage_path('logs/mail.log');
        if (! File::exists($path)) {
            $this->info('Ainda não existem emails locais. Pede um link na plataforma.');

            return self::SUCCESS;
        }
        $contents = html_entity_decode(str_replace(["=\r\n", "=\n"], '', File::get($path)));
        preg_match_all('~https?://[^\s<>"\)\]]+~', $contents, $matches);
        $prefix = rtrim(config('app.frontend_url'), '/').'/#/';
        $links = array_values(array_unique(array_filter($matches[0], fn (string $link) => str_starts_with($link, $prefix))));
        $limit = max(1, min(20, (int) $this->option('limit')));
        $this->info('Links mais recentes (apenas no teu computador):');
        foreach (array_slice(array_reverse($links), 0, $limit) as $link) {
            $this->line($link);
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
