<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceAddress;
use RuntimeException;

/**
 * No record has the ID, or the code is not a built-in source available on the portal.
 *
 * @internal
 */
final class TrackingSourceNotFoundException extends RuntimeException
{
	public function __construct(
		public readonly SourceAddress $address,
	)
	{
		parent::__construct('Tracking source was not found.');
	}
}
