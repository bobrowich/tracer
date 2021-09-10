<?php
declare(strict_types=1);

namespace Letu\Tracer;

use Exception;
use Jaeger\{Config, Jaeger, Scope, Span, SpanContext};
use OpenTracing\Reference;
use Illuminate\Support\Facades\{Auth, Log, Request, URL};
use const Jaeger\Constants\{X_B3_TRACEID, X_B3_PARENT_SPANID, X_B3_SPANID};

class TracingService
{
    /** @var Jaeger|null */
    private $tracer;

    /** @var Span */
    private $globalSpan;

    public function __construct()
    {
        $this->makeTracer();
    }

    public function initGlobalSpan(): void
    {
        $this->makeGlobalSpan();
        $this->setTypeTag();
        $this->logRequest();
    }

    public function getTracer(): ?Jaeger
    {
        return $this->tracer;
    }

    public function getGlobalSpan(): Span
    {
        return $this->globalSpan;
    }

    public function setTag(string $key, string $value): void
    {
        if ($this->globalSpan === null) {
            return;
        }
        $this->globalSpan->setTag($key, $value);
    }

    public function addUserInfo(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $this->setTag('user_id', (string)$user->getAuthIdentifier());
        $this->setTag('merchant_id', (string)$user->getMerchantId());

        $this->log(
            [
                'user' =>
                    [
                        'id' => $user->getAuthIdentifier(),
                        'name' => $user->getName(),
                        'roles' => implode(', ', $user->getRoles()),
                        'merchant_id' => $user->getMerchantId(),
                        'status' => $user->getStatus(),
                    ],
            ]
        );
    }

    public function makeHeaders(): array
    {
        if (null === $context = $this->globalSpan->getContext() ) {
            return [];
        }

        return [
            X_B3_TRACEID => $context->traceIdHigh,
            X_B3_SPANID => $context->spanId,
            X_B3_PARENT_SPANID => $context->parentId,
        ];
    }

    /**
     * Starting an active span will always use the currently active span as a parent
     * @see https://github.com/opentracing/opentracing-php#active-spans-and-scope-manager
     *
     * @todo Register names and chek for uniqueness
     * @todo Maybe register spans themselves instead of returning Scope
     */
    public function startActiveSpan(string $name): Scope
    {
        $spanContext = $this->fillIdsFromHeaders();
        $options = $spanContext !== null ? [Reference::CHILD_OF => $spanContext] : [];

        return $this->tracer->startActiveSpan($name, $options);
    }

    private function log(array $fields): void
    {
        if ($this->globalSpan === null) {
            return;
        }
        $this->globalSpan->log($fields);
    }

    private function makeTracer(): void
    {
        if (null === $config = $this->makeConfig()) {
            return;
        }

        if (null === $hostAndPort = $this->getHostPortEnv()) {
            return;
        }

        if (null === $serviceName = \Config::get('app.app_name_for_tracer')) {
            Log::error('Couldn\'t get APP_NAME_FOR_TRACER env param');
            return;
        }

        try {
            if (null === $this->tracer = $config->initTracer($serviceName, $hostAndPort)) {
                Log::error('Couldn\'t instantiate Jaeger\'s tracer');
            }
        } catch (Exception $e) {
            Log::error($e->getMessage(), ['Couldn\'t instantiate Jaeger\'s tracer']);
        }
    }

    private function makeConfig(): ?Config
    {
        if (null === $config = Config::getInstance()) {
            Log::error('Couldn\'t instantiate Jaeger\'s config');
            return null;
        }

        $config->gen128bit();

        return $config;
    }

    private function makeGlobalSpan(): void
    {
        $spanContext = $this->fillIdsFromHeaders();
        $options = $spanContext !== null ? [Reference::CHILD_OF => $spanContext] : [];

        $this->globalSpan = $this->tracer->startSpan(
            $this->composeOperationName(), $options
        );
    }

    private function getHostPortEnv(): ?string
    {
        if (empty($host = env('JAEGER_AGENT_HOST'))) {
            Log::error('Couldn\'t get JAEGER_AGENT_HOST env param');
            return null;
        }
        if (empty($port = env('JAEGER_AGENT_PORT'))) {
            Log::error('Couldn\'t get JAEGER_AGENT_PORT env param');
            return null;
        }

        return $host . ':' . $port;
    }

    private function composeOperationName(): string
    {
        return URL::getRequest()->getMethod() . ' ' . URL::getRequest()->path();
    }

    private function setTypeTag(): void
    {
        $this->globalSpan->setTag(
            'type',
            app()->runningInConsole() ? 'kafka' : 'http'
        );
    }

    private function fillIdsFromHeaders(): ?SpanContext
    {
        $headers = Request::header();

        if (!isset($headers[X_B3_TRACEID][0], $headers[X_B3_SPANID][0])) {
            return null;
        }

        $traceId = (int)$headers[X_B3_TRACEID][0];
        $spanId = (int)$headers[X_B3_SPANID][0];
        $parentId = $headers[X_B3_PARENT_SPANID][0] ? (int)$headers[X_B3_PARENT_SPANID][0] : 0;

        $spanContext = new SpanContext($spanId, $parentId, 1);
        $spanContext->traceIdHigh = $traceId;
        $spanContext->traceIdLow = $spanId;

        return $spanContext;
    }

    private function logRequest(): void
    {
        $this->log(
            [
                'request' =>
                    [
                        'host' => Request::getHost(),
                        'method' => Request::getMethod(),
                        'uri' => Request::url(),
                        'input' => Request::all(),
                    ],
            ]
        );
    }
}
