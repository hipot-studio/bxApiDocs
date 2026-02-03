<?php

declare(strict_types=1);

namespace Bitrix\Disk\Realtime\Events;

use Bitrix\Disk\Realtime\Event;
use Bitrix\Disk\Realtime\Tags\AvailableDocumentSessionCountTag;

class AvailableDocumentSessionCountEvent extends Event
{
	public function sendToUsers(): void
	{
		$this->send([
			new AvailableDocumentSessionCountTag(),
		]);
	}
}