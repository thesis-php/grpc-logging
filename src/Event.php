<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

/**
 * @api
 */
enum Event
{
    case Start;
    case Finish;
    case PayloadSent;
    case PayloadReceived;
}
