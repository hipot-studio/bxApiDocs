<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\Provider\Item\RestItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Provider\Item\RestMyCompanyItemProvider;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\Item as ItemEntity;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Engine\Action;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

final class MyCompany extends Item
{
	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		$permissions = Container::getInstance()->getUserPermissions($this->userId)->myCompany();
		$isAllowed = match ($action->getName())
		{
			'get', 'list', 'aggregate' => $permissions->canRead(),
			'add' => $permissions->canAdd(),
			'update', 'addFieldValue', 'deleteFieldValue' => $permissions->canUpdate(),
			'delete' => $permissions->canDelete(),
			default => false,
		};

		if (!$isAllowed)
		{
			throw new AccessDeniedException();
		}

		return true;
	}

	protected function getRestItemProvider(EntityType $entityType): RestItemProvider
	{
		return new RestMyCompanyItemProvider($this->userId);
	}

	protected function prepareItemForAdd(ItemEntity $item): void
	{
		if (!$item instanceof Company)
		{
			throw new \LogicException('My company controller requires the company entity type.');
		}

		$item->setIsMyCompany(true);
	}
}
