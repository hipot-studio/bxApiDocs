<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async;

use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Bitrix\Socialnetwork\V2\Internal\Async\QueueId;

abstract class AbstractStructureSyncMessage extends AbstractMessage
{
	abstract public function getItemId(): string;

	public function sendToQueue(): void
	{
		$this->send(QueueId::StructureSync->value, [new ItemIdParam($this->getItemId())]);
	}
}
