<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * A base channel of the end-to-end analytics and whether it is set up on the portal.
 *
 * @internal
 */
final readonly class Channel
{
	public function __construct(
		public ChannelCode $code,
		public string $name,
		public bool $isConfigurable,
		public bool $isConfigured,
	)
	{
	}
}
