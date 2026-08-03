<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging\Internal;

/**
 * @internal
 */
enum Component: string
{
    case Client = 'client';
    case Server = 'server';

    /**
     * @return non-empty-string
     */
    public function sends(): string
    {
        return $this === self::Client ? 'request' : 'response';
    }

    /**
     * @return non-empty-string
     */
    public function receives(): string
    {
        return $this === self::Client ? 'response' : 'request';
    }
}
