<?php
declare(strict_types=1);

namespace Bitrix\Disk\Realtime\Events;

use Bitrix\Disk\Realtime\Event;
use Bitrix\Disk\Realtime\Tags\OnlyOfficeForceReloadTag;

class OnlyOfficeForceReloadEvent extends Event
{
	public const COMMAND = 'diskOnlyOfficeForceReloadCommand';

	public function sendToUsers(): void
	{
		$this->send([
			new OnlyOfficeForceReloadTag(),
		]);
	}
}