<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging\Internal;

use Thesis\Grpc\RpcType;

/**
 * @internal
 */
enum Type: string
{
    case Unary = 'unary';
    case ClientStream = 'client_stream';
    case ServerStream = 'server_stream';
    case BidiStream = 'bidi_stream';

    public static function fromRpcType(RpcType $type): self
    {
        return match ($type) {
            RpcType::Unary => self::Unary,
            RpcType::ClientStream => self::ClientStream,
            RpcType::ServerStream => self::ServerStream,
            RpcType::BidirectionalStream => self::BidiStream,
        };
    }
}
