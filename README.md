# thesis/grpc-logging

PSR-3 logging middleware for [thesis/grpc](https://github.com/thesis-php/grpc): client and server
interceptors that log every unary and streaming RPC with its method, type, status code and duration,
at a log level derived from the gRPC status.

## Contents

- [Installation](#installation)
- [Usage](#usage)
- [Configuration](#configuration)
- [Log levels](#log-levels)
- [Log fields](#log-fields)
- [How it works](#how-it-works)

## Installation

```bash
composer require thesis/grpc-logging
```

## Usage

There are two interceptors — one per side. Each implements both the unary and stream contracts of its
side, so a single instance covers all RPC types.

```php
use Psr\Log\LoggerInterface;
use Thesis\Grpc\Client;
use Thesis\Grpc\Logging;
use Thesis\Grpc\Server;

/** @var LoggerInterface $logger */
$config = new Logging\Config($logger);

// Client
$client = new Client\Builder()
    ->withUnaryInterceptors(new Logging\ClientInterceptor($config))
    ->withStreamInterceptors(new Logging\ClientInterceptor($config))
    ->build();

// Server
$server = new Server\Builder()
    ->withUnaryInterceptors(new Logging\ServerInterceptor($config))
    ->withStreamInterceptors(new Logging\ServerInterceptor($config))
    ->build();
```

The `$logger` is any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger (Monolog, etc.).

## Configuration

`Logging\Config` is an immutable `dto`:

```php
use Thesis\Grpc\Logging\Config;
use Thesis\Grpc\Logging\Event;

new Config(
    logger: $logger,                          // required PSR-3 logger
    levels: new Logging\DefaultLevels(),      // gRPC status → PSR-3 level
    events: [Event::Start, Event::Finish],    // which points of the call to log
);
```

`Event` cases:

- `Start` — a `started call` record when the call begins.
- `Finish` — a `finished call` record with the status and duration when it ends.
- `PayloadSent` / `PayloadReceived` — a record for **each** message, named by role and direction:
  the client logs `request sent` / `response received`, the server `response sent` / `request received`,
  with the message object under `grpc.request.content` / `grpc.response.content`. Off by default.

To log only completed calls, pass `events: [Event::Finish]`. To also log message payloads (verbose,
and potentially sensitive), add `Event::PayloadSent` / `Event::PayloadReceived`.

## Log levels

The finish record's level is derived from the call's gRPC status by a `Levels` implementation.
`DefaultLevels` mirrors the grpc-ecosystem mapping — client-caused and expected statuses stay
informational, transient ones warn, and genuine server faults are errors:

| Level     | Statuses                                                                                                                        |
|-----------|---------------------------------------------------------------------------------------------------------------------------------|
| `info`    | `OK`, `CANCELLED`, `INVALID_ARGUMENT`, `NOT_FOUND`, `ALREADY_EXISTS`, `UNAUTHENTICATED`                                         |
| `warning` | `DEADLINE_EXCEEDED`, `PERMISSION_DENIED`, `RESOURCE_EXHAUSTED`, `FAILED_PRECONDITION`, `ABORTED`, `OUT_OF_RANGE`, `UNAVAILABLE` |
| `error`   | `UNKNOWN`, `UNIMPLEMENTED`, `INTERNAL`, `DATA_LOSS`                                                                             |

Provide your own by implementing `Levels`:

```php
use Google\Rpc\Code;
use Psr\Log\LogLevel;
use Thesis\Grpc\Logging\Levels;

final class QuietLevels implements Levels
{
    public function level(Code $code): string
    {
        return $code === Code::OK ? LogLevel::DEBUG : LogLevel::WARNING;
    }
}
```

The start record is emitted at the `OK` level.

## Log fields

Each record carries a structured PSR-3 context:

| Field                                            | Example                                                  | Notes                                                                      |
|--------------------------------------------------|----------------------------------------------------------|----------------------------------------------------------------------------|
| `grpc.component`                                 | `client` / `server`                                      | Which side emitted the record.                                             |
| `grpc.method`                                    | `/acme.Api/Get`                                          | Full method name.                                                          |
| `grpc.type`                                      | `unary`, `server_stream`, `client_stream`, `bidi_stream` | RPC type.                                                                  |
| `grpc.code`                                      | `0`                                                      | Numeric gRPC status (finish record only).                                  |
| `grpc.code_name`                                 | `OK`                                                     | Status name (finish record only).                                          |
| `grpc.time_ms`                                   | `4.21`                                                   | Wall-clock duration in milliseconds (finish record only).                  |
| `grpc.error`                                     | *(exception object)*                                     | The thrown exception (finish record of a failed call only).                |
| `grpc.request.content` / `grpc.response.content` | *(message object)*                                       | The payload (payload records only); format it in your PSR-3 handler.       |
| `grpc.send.time_ms` / `grpc.recv.time_ms`        | `0.18`                                                   | Duration of that individual send/receive (streaming payload records only). |

Record messages are `started call`, `finished call`, and — for payload records — `request sent`,
`response sent`, `request received` and `response received`.

## How it works

- **Unary** calls are logged around the invocation: a start record (if enabled), then a finish record
  carrying the status and duration — `OK` on success, or the failure's status (an `InvokeError`'s
  code, `CANCELLED` for a cancellation, `UNKNOWN` otherwise) before the error is rethrown.
- **Server streams** are logged the same way: the interceptor wraps the (blocking) handler call, so
  the finish record is written when the handler returns or throws.
- **Client streams** return before the RPC completes, so the interceptor logs the start immediately
  and wraps the stream in a decorator that completes the span at the terminal event, chosen by the
  RPC's cardinality (`Invoke::$type`), the way Go's grpc-middleware keys off the stream descriptor:
  - a **single-response** call (unary-over-stream, client streaming) terminates on its sole
    `receive()` — success is the finish (`OK`), a throw is the failure;
  - a **server stream** terminates on **iteration** — the iterator completing is the success, it
    throwing is the failure. (`receive()` has no clean end-of-stream — it throws even on success — so a
    server stream drained via raw `receive()` calls logs its messages but not a finish record.)
- **Payload logging**, when enabled, records every message. Unary payloads are logged inline (the
  request and response are right there). Streaming payloads are logged by the stream decorators as
  messages flow — the client decorator is always in place, and the server stream is wrapped only when
  a payload event is enabled, so the common lifecycle-only path stays decorator-free.
