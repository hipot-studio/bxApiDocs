<?php

namespace Bitrix\DocumentGenerator\Integration\HumanResources\Service;

use Bitrix\DocumentGenerator\Integration\HumanResources;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\Main\Application;
use Throwable;

final class StorageService
{
	public function isCompanyStructureConverted(bool $checkIsEmployeesTransferred = true): bool
	{
		if (!HumanResources::getInstance()->isAvailable())
		{
			return false;
		}

		try {
			return Storage::instance()->isCompanyStructureConverted($checkIsEmployeesTransferred);
		}
		catch (Throwable $error)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($error);

			return false;
		}
	}
}
