<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalMailTest extends TestCase
{
    public function test_mail_links_are_only_available_in_local_log_mode(): void
    {
        $this->artisan('app:mail-links')->assertExitCode(1);
    }

    public function test_the_command_preserves_tokens_and_excludes_external_links(): void
    {
        $this->app->instance('env', 'local');
        config(['mail.default' => 'log']);
        $url = config('app.frontend_url').'/#/repor-palavra-passe?token=deadbeef123&email=maria%40example.test';
        File::shouldReceive('exists')->once()->andReturnTrue();
        File::shouldReceive('get')->once()->andReturn("Subject: Test =DA\nLink: ".$url."\n<a href=\"".$url."\">Link</a>\nhttps://external.example.test/private");
        $this->artisan('app:mail-links', ['--limit' => 1])
            ->expectsOutputToContain($url)->doesntExpectOutputToContain('external.example.test')->assertExitCode(0);
    }
}
