<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\CustomServer;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Throwable;

class UnregisterCustomServerCommandHandler
{
	/**
	 * @param UnregisterCustomServerCommand $command
	 * @return void
	 * @throws Throwable
	 * @throws SqlQueryException
	 */
	public function __invoke(UnregisterCustomServerCommand $command): void
	{
		$customServerType = Configuration::getDefaultViewerCustomConfigType();

		if (!$customServerType instanceof CustomServerTypes)
		{
			return;
		}

		$connection = Application::getConnection();

		$connection->startTransaction();

		try
		{
			Configuration::removeDefaultViewerCustomConfigType();
			DocumentSessionTable::deleteByService(DocumentService::OnlyOffice);
			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}
	}
}
