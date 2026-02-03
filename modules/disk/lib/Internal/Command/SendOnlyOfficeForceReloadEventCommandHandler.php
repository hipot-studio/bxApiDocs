<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command;

use Bitrix\Disk\Realtime\Events\OnlyOfficeForceReloadEvent;

class SendOnlyOfficeForceReloadEventCommandHandler
{
	/**
	 * @param SendOnlyOfficeForceReloadEventCommand $command
	 * @return void
	 */
	public function __invoke(SendOnlyOfficeForceReloadEventCommand $command): void
	{
		(new OnlyOfficeForceReloadEvent(
			category: OnlyOfficeForceReloadEvent::COMMAND,
			data: [
				'newServersType' => $command->newServersType->value,
			],
		))->sendToUsers();
	}
}