<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Google\Rpc\Code;
use Psr\Log\LogLevel;
use Testo\Assert;
use Testo\Test;

#[Test]
final class DefaultLevelsTest
{
    public function level(): void
    {
        $levels = new DefaultLevels();

        Assert::same($levels->level(Code::OK), LogLevel::INFO);
        Assert::same($levels->level(Code::NOT_FOUND), LogLevel::INFO);
        Assert::same($levels->level(Code::UNAVAILABLE), LogLevel::WARNING);
        Assert::same($levels->level(Code::PERMISSION_DENIED), LogLevel::WARNING);
        Assert::same($levels->level(Code::INTERNAL), LogLevel::ERROR);
        Assert::same($levels->level(Code::DATA_LOSS), LogLevel::ERROR);
    }
}
