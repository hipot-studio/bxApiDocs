<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\BoardIndexDto;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

final class IndexGetResponse extends GetResponse
{
	public function __construct(BoardIndexDto $item)
	{
		parent::__construct($item);
	}
}
