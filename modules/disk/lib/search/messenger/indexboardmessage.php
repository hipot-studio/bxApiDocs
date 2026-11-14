<?php

namespace Bitrix\Disk\Search\Messenger;

use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\ProcessingParam\DelayParam;

class IndexBoardMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $objectId,
		public readonly int $globalContentVersion,
	)
	{
	}

	public function schedule(): void
	{
		$this->send('index_board', [new DelayParam(10)]);
	}
}
