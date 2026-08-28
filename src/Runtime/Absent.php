<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

/**
 * The default a generated method gives a parameter the contract declares
 * required.
 *
 * It exists for the recording phase: a specification that ends with
 * `Arg::rest()` physically passes fewer arguments than the method declares,
 * and PHP would refuse the call with `ArgumentCountError` before the body
 * could signal. With the sentinel in place the call goes through, the body
 * collects it, and `InvocationSignal` strips the sentinels back off.
 *
 * Outside recording the sentinel means the code under test omitted a required
 * argument, and dispatch answers with the `ArgumentCountError` PHP itself
 * would have raised — a double must not be more permissive about arity than
 * the real implementation.
 *
 * An enum case rather than a marker object because a parameter default must be
 * a constant expression, and an enum case is one.
 *
 * @internal
 */
enum Absent
{
    case Argument;
}
