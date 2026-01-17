<?php

namespace Bitrix\DocumentGenerator\Integration\HumanResources\Service;

use Bitrix\DocumentGenerator\Integration\HumanResources;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Application;
use Throwable;

final class NodeMemberService
{
	public function getAllEmployees(
		int $nodeId,
		bool $withAllChildNodes = false,
		bool $onlyActive = true,
	): ?NodeMemberCollection
	{
		if (!HumanResources::getInstance()->isAvailable())
		{
			return null;
		}

		try {
			return Container
				::getNodeMemberService()
				->getAllEmployees(
					$nodeId,
					$withAllChildNodes,
					$onlyActive,
				)
			;
		}
		catch (Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}
}
