<?php

namespace Bitrix\DocumentGenerator\Integration\HumanResources\Service;

use Bitrix\DocumentGenerator\Integration\HumanResources;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\Main\Application;
use Throwable;

final class AccessCodeService
{
	public function buildAccessCode(string $accessCodeTypeValue, int $nodeId): ?string
	{
		if (
			!HumanResources::getInstance()->isAvailable()
			|| !HumanResources::getInstance()->getStorageService()->isCompanyStructureConverted(false)
		)
		{
			return null;
		}

		try {
			return AccessCodeType::tryFrom($accessCodeTypeValue)?->buildAccessCode($nodeId);
		}
		catch (Throwable $error)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($error);

			return null;
		}
	}
}
