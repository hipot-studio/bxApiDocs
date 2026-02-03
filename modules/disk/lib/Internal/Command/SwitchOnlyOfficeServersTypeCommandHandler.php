<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command;

use Bitrix\Disk\Configuration;

class SwitchOnlyOfficeServersTypeCommandHandler
{
	/**
	 * @param SwitchOnlyOfficeServersTypeCommand $command
	 * @return void
	 */
	public function __invoke(SwitchOnlyOfficeServersTypeCommand $command): void
	{
		if (Configuration::getOnlyOfficeServersType() === $command->newServersType)
		{
			return;
		}

		Configuration::setOnlyOfficeServersType($command->newServersType);
	}
}