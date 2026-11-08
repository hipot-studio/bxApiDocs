<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * A channel of a trace: the kind of contact and its value, such as a form id or a phone number.
 *
 * @internal
 */
final readonly class TraceChannel
{
	public function __construct(
		public ChannelCode $code,
		public string $value,
	)
	{
	}
}
