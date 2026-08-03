<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Google\Rpc\Code;
use Psr\Log\LogLevel;

/**
 * @api
 */
final readonly class DefaultLevels implements Levels
{
    #[\Override]
    public function level(Code $code): string
    {
        return match ($code) {
            Code::OK,
            Code::CANCELLED,
            Code::INVALID_ARGUMENT,
            Code::NOT_FOUND,
            Code::ALREADY_EXISTS,
            Code::UNAUTHENTICATED => LogLevel::INFO,
            Code::DEADLINE_EXCEEDED,
            Code::PERMISSION_DENIED,
            Code::RESOURCE_EXHAUSTED,
            Code::FAILED_PRECONDITION,
            Code::ABORTED,
            Code::OUT_OF_RANGE,
            Code::UNAVAILABLE => LogLevel::WARNING,
            Code::UNKNOWN,
            Code::UNIMPLEMENTED,
            Code::INTERNAL,
            Code::DATA_LOSS => LogLevel::ERROR,
        };
    }
}
