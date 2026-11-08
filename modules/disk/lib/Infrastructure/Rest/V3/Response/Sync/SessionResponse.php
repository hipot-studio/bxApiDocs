<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\SessionDto;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

/**
 * The single-item envelope of the contour: {"item": {...}}.
 *
 * Creating a session writes a row, yet the answer is the canonical read-after-write one and not an
 * identifier: the client needs the positions and the boundaries the portal has just fixed, and a
 * bare id would send it straight back for them.
 */
final class SessionResponse extends GetResponse
{
	public function __construct(SessionDto $item)
	{
		parent::__construct($item);
	}
}
