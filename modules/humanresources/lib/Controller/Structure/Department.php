<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Controller\Structure;

use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Command\Structure\Node\CreateDepartmentCommand;
use Bitrix\HumanResources\Engine\Controller;
use Bitrix\HumanResources\Exception\CommandException;
use Bitrix\HumanResources\Exception\CommandValidateException;
use Bitrix\HumanResources\Type\AccessibleItemType;
use Bitrix\HumanResources\Internals\Attribute;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Item;
use Bitrix\Main\Error;

class Department extends Controller
{
	#[Attribute\StructureActionAccess(
		permission: StructureActionDictionary::ACTION_DEPARTMENT_CREATE,
		itemType: AccessibleItemType::NODE,
		itemParentIdRequestKey: 'parentId',
	)]
	public function createAction(
		string $name,
		int $parentId,
		Item\Structure $structure,
		?string $description = null,
		array $userIds = [],
	): array
	{
		$node = new Item\Node(
			name: $name,
			type: NodeEntityType::DEPARTMENT,
			structureId: $structure->id,
			parentId: $parentId,
			description: $description,
		);

		try
		{
			$commandResult = (new CreateDepartmentCommand($node, $userIds))->run();

			if (!$commandResult->isSuccess())
			{
				$this->addErrors($commandResult->getErrors());

				return [];
			}
		}
		catch (CommandException|CommandValidateException $e)
		{
			$this->addError(new Error($e->getMessage()));

			return [];
		}

		return [
			$commandResult->node,
		];
	}
}