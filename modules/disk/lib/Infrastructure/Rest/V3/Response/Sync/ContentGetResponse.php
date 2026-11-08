<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ContentLinkDto;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

/**
 * The single-item envelope of the contour: {"item": {...}}.
 *
 * Nothing of the file itself is repeated here - not its name, not its size, not its type. The card the
 * client already holds says all of that, and saying it a second time would give a client two sources
 * for one fact and a reason to trust the fresher-looking one.
 */
final class ContentGetResponse extends GetResponse
{
	public function __construct(ContentLinkDto $item)
	{
		parent::__construct($item);
	}
}
