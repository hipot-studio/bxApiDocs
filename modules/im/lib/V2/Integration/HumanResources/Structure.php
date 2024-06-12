<?php

namespace Bitrix\Im\V2\Integration\HumanResources;

use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Loader;

class Structure
{
	protected Chat $chat;

	public function __construct(Chat $chat)
	{
		$this->chat = $chat;
	}

	public static function splitEntities(array $entities): array
	{
		$users = [];
		$structureNodes = [];

		foreach ($entities as $entity)
		{
			if (str_starts_with($entity, 'U'))
			{
				$users[] = (int)mb_substr($entity, 1);
			}
			if (str_starts_with($entity, 'D') || str_starts_with($entity, 'DR'))
			{
				$structureNodes[] = $entity;
			}
		}

		return [$users, $structureNodes];
	}

	public function link(array $structureNodeIds): Result
	{
		$result = new Result();

		if (empty($structureNodeIds))
		{
			return $result;
		}

		if (!Loader::includeModule('humanresources'))
		{
			return $result->addError(new Error(Error::LINK_ERROR));
		}

		$nodeRelationService = Container::getNodeRelationService();

		foreach ($structureNodeIds as $structureNodeId)
		{
			try {
				/*$nodeRelationService->linkEntityToNodeByAccessCode(
					$structureNodeId,
					RelationEntityType::CHAT,
					$this->chat->getId()
				);*/
			}
			catch (\Exception $exception)
			{
				$result->addError(new Error(Error::LINK_ERROR));
			}
		}

		return $result;
	}
}