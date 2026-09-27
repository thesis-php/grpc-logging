<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging\Internal;

use Amp\CancelledException;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc\InvokeError;
use Thesis\Grpc\Logging\Config;
use Thesis\Grpc\Logging\Event;

/**
 * @internal
 */
final readonly class Span
{
    private float $startedAt;

    /**
     * @param non-empty-string $method
     */
    public function __construct(
        private Config $config,
        private Component $component,
        private string $method,
        private Type $type,
    ) {
        $this->startedAt = (float) hrtime(true);

        if ($this->config->enabled(Event::Start)) {
            $this->write(Code::OK, 'started call');
        }
    }

    public function payloadSent(object $message, ?float $timeMs = null): void
    {
        if (!$this->config->enabled(Event::PayloadSent)) {
            return;
        }

        $role = $this->component->sends();

        $context = ["grpc.{$role}.content" => $message];

        if ($timeMs !== null) {
            $context['grpc.send.time_ms'] = $timeMs;
        }

        $this->write(Code::OK, "{$role} sent", $context);
    }

    public function payloadReceived(object $message, ?float $timeMs = null): void
    {
        if (!$this->config->enabled(Event::PayloadReceived)) {
            return;
        }

        $role = $this->component->receives();

        $context = ["grpc.{$role}.content" => $message];

        if ($timeMs !== null) {
            $context['grpc.recv.time_ms'] = $timeMs;
        }

        $this->write(Code::OK, "{$role} received", $context);
    }

    public function sending(): MessageSpan
    {
        return new MessageSpan($this->payloadSent(...), self::elapsed(...));
    }

    public function receiving(): MessageSpan
    {
        return new MessageSpan($this->payloadReceived(...), self::elapsed(...));
    }

    public function complete(Code $code): void
    {
        $this->stop($code);
    }

    public function error(\Throwable $error): void
    {
        $code = match (true) {
            $error instanceof InvokeError => $error->statusCode,
            $error instanceof CancelledException => Code::CANCELLED,
            default => Code::UNKNOWN,
        };

        $this->stop($code, $error);
    }

    private function stop(Code $code, ?\Throwable $error = null): void
    {
        if (!$this->config->enabled(Event::Finish)) {
            return;
        }

        $context = [
            'grpc.code' => $code->value,
            'grpc.code_name' => $code->name,
            'grpc.time_ms' => self::elapsed($this->startedAt),
        ];

        if ($error !== null) {
            $context['grpc.error'] = $error;
        }

        $this->write($code, 'finished call', $context);
    }

    /**
     * @param non-empty-string $message
     * @param array<non-empty-string, mixed> $context
     */
    private function write(Code $code, string $message, array $context = []): void
    {
        $this->config->logger->log(
            $this->config->levels->level($code),
            $message,
            [
                'grpc.component' => $this->component->value,
                'grpc.method' => $this->method,
                'grpc.type' => $this->type->value,
                ...$context,
            ],
        );
    }

    private static function elapsed(float $started): float
    {
        return (hrtime(true) - $started) / 1e6;
    }
}
