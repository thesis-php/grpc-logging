<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Psr\Log\LogLevel;
use Thesis\Google\Rpc\Code;

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
