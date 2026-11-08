<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\CapabilitiesDto;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

/**
 * The single-item envelope of the contour: {"item": {...}}.
 *
 * It extends the canonical GetResponse so the answer is serialised by the same path as every other
 * V3 read, and narrows the item to what this method actually answers with.
 */
final class CapabilitiesResponse extends GetResponse
{
	public function __construct(CapabilitiesDto $item)
	{
		parent::__construct($item);
	}
}
