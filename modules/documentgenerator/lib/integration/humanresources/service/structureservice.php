<?php

namespace Bitrix\DocumentGenerator\Integration\HumanResources\Service;

use Bitrix\DocumentGenerator\Integration\HumanResources;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Item\Structure;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Application;
use Throwable;

final class StructureService
{
	/** @see Structure::DEFAULT_STRUCTURE_XML_ID */
	public const DEFAULT_STRUCTURE_XML_ID = 'COMPANY_STRUCTURE';

	public function getStructureByXmlId(string $xmlId): ?Structure
	{
		if (!HumanResources::getInstance()->isAvailable())
		{
			return null;
		}

		try {
			return Container::getStructureRepository()->getByXmlId($xmlId);
		}
		catch (Throwable $error)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($error);

			return null;
		}
	}
}
