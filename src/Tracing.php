<?php
declare(strict_types=1);

namespace Letu\Tracer;

use Closure;
use Illuminate\Http\Request;

class Tracing
{
    private $tracingService;

    public function __construct(TracingService $tracingService)
    {
        $this->tracingService = $tracingService;
    }

    public function handle(Request $request, Closure $next)
    {
        $this->tracingService->addUserInfo();

        return $next($request);
    }
}