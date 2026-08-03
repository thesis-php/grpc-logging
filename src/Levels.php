<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Google\Rpc\Code;
use Psr\Log\LogLevel;

/**
 * @api
 */
interface Levels
{
    /**
     * @return LogLevel::*
     */
    public function level(Code $code): string;
}
