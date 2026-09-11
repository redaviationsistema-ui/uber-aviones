<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath(sys_get_temp_dir().'/uberaviones_phase1_test_storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
$c = config('database.connections.pgsql');
if (! app()->environment('testing') || config('database.default') !== 'pgsql'
    || ! preg_match('/^uberaviones_phase1_concurrency_test_[0-9]+$/', (string) $c['database'])
    || ! in_array($c['host'], ['/tmp', '127.0.0.1', 'localhost'], true)
    || ! empty($c['url'])) {
    fwrite(STDERR, "Expected testing environment and explicit local phase1 concurrency database, without DB_URL.\n");
    exit(1);
}
config(['cache.default' => 'array', 'queue.default' => 'sync', 'mail.default' => 'array', 'broadcasting.default' => 'null']);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\DB::statement("SET statement_timeout = '20s'");
return $app;
