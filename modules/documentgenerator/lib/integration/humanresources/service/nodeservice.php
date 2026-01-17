<?php

namespace Bitrix\DocumentGenerator\Integration\HumanResources\Service;

use Bitrix\DocumentGenerator\Integration\HumanResources;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Application;
use Throwable;

final class NodeService
{
	public function findAllByUserId(int $userId): ?NodeCollection
	{
		if (
			!HumanResources::getInstance()->isAvailable()
			|| !HumanResources::getInstance()->getStorageService()->isCompanyStructureConverted()
		)
		{
			return null;
		}

		try {
			return Container::getNodeRepository()->findAllByUserId($userId);
		}
		catch (Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}

	public function getNodeByAccessCode(string $accessCode): ?Node
	{
		if (!HumanResources::getInstance()->isAvailable())
		{
			return null;
		}

		try {
			return Container::getNodeRepository()->getByAccessCode($accessCode);
		}
		catch (Throwable $error)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($error);

			return null;
		}
	}

	public function findAllByAccessCodes(array $departments): ?NodeCollection
	{
		if (!HumanResources::getInstance()->isAvailable())
		{
			return null;
		}

		try {
			return Container::getNodeRepository()->findAllByAccessCodes($departments);
		}
		catch (Throwable $error)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($error);

			return null;
		}
	}

	public function getRootNodeByStructureId(int $structureId): ?Node
	{
		if (!HumanResources::getInstance()->isAvailable())
		{
			return null;
		}

		try {
			return Container::getNodeRepository()->getRootNodeByStructureId($structureId);
		}
		catch (Throwable $error)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($error);

			return null;
		}
	}

	public function getRootDepartment(): ?Node
	{
		if (!HumanResources::getInstance()->isAvailable())
		{
			return null;
		}

		try {
			$structure = HumanResources::getInstance()
				->getStructureService()
				->getStructureByXmlId(StructureService::DEFAULT_STRUCTURE_XML_ID);

			if ($structure === null)
			{
				return null;
			}

			return $this->getRootNodeByStructureId($structure->id);
		}
		catch (Throwable $error)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($error);

			return null;
		}
	}
}
