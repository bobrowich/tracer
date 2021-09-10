<?php
declare(strict_types=1);

namespace Letu\Tracer;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\{DB, Event, Log};
use Illuminate\Support\ServiceProvider;

class TracingServiceProvider extends ServiceProvider
{
    private const TRACER = 'tracer';
    private const GLOBAL_SPAN = 'tracer.globalSpan';

    public function register(): void
    {
        $this->app->singleton(TracingService::class);
    }

    public function boot(TracingService $service): void
    {
        if (!$this->binding($service)) {
            return;
        }

        app()->terminating(function () {
            app(self::GLOBAL_SPAN)->finish();
            app(self::TRACER)->flush();
        });

        $this->listenEvents();
    }

    private function binding(TracingService $service): bool
    {
        if (null === $tracer = $service->getTracer()) {
            return false;
        }

        $service->initGlobalSpan();
        $globalSpan = $service->getGlobalSpan();

        $this->app->instance(self::TRACER, $tracer);
        $this->app->instance(self::GLOBAL_SPAN, $globalSpan);

        return true;
    }

    private function listenEvents(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $message) {
            app(self::GLOBAL_SPAN)->log((array)$message);
        });

        DB::listen(function ($query) {
            Log::debug('DB query', [
                'query' => str_replace('"', "'", $query->sql),
                'time' => $query->time . ' ms',
            ]);
        });
    }
}
