<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use RuntimeException;

/**
 * The channel of a trace is not one of the channels a trace can come through.
 *
 * @internal
 */
final class TraceChannelInvalidException extends RuntimeException
{
	public function __construct(
		public readonly string $channelCode,
	)
	{
		parent::__construct(sprintf('Tracking channel `%s` is unknown.', $channelCode));
	}
}
