<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

use Bitrix\Crm\Binding\EntityBinding;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\ParentFieldManager;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Applies the context signed into a ticket to the item created by the CRM form.
 */
class SubmittedItem
{
	private const ERROR_CODE_ENTITY_TYPE_NOT_FOUND = 'CRM_START_CATALOG_ENTITY_TYPE_NOT_FOUND';

	/**
	 * The context is put on the item by the system: the queue of the form may have made somebody else
	 * responsible. The parent is set while the employee may still read it; the funnel and the stage only
	 * where he may create items.
	 */
	public function applyContext(Item $item, array $context, int $userId): Result
	{
		$factory = $this->getFactory($item->getEntityTypeId());
		if ($factory === null)
		{
			return (new Result())->addError(
				new Error('Smart process of the item was not found', self::ERROR_CODE_ENTITY_TYPE_NOT_FOUND),
			);
		}

		$permissions = Container::getInstance()->getUserPermissions($userId);
		if (!$this->fillContext($factory, $item, $context, $permissions))
		{
			// the catalog of processes opens the same form without any context at all, and the item
			// then stands where the form itself put it
			return new Result();
		}

		return $factory->getUpdateOperation($item)
			->disableCheckAccess()
			->disableCheckRequiredUserFields()
			->launch()
		;
	}

	/**
	 * Returns whether anything is left to save.
	 */
	private function fillContext(Factory $factory, Item $item, array $context, UserPermissions $permissions): bool
	{
		$isChanged = false;
		$entityTypeId = $item->getEntityTypeId();

		$parentTypeId = (int)($context['parentTypeId'] ?? 0);
		$parentId = (int)($context['parentId'] ?? 0);
		if (
			(new EntryContextValidator())->isParentAllowed($entityTypeId, $parentTypeId, $parentId, $permissions)
			&& $this->bindParent($factory, $item, $parentTypeId, $parentId)
		)
		{
			$isChanged = true;
		}

		$categoryId = (int)($context['categoryId'] ?? 0);
		if (
			$categoryId > 0
			&& $item->isCategoriesSupported()
			&& (int)$item->getCategoryId() !== $categoryId
			&& $factory->getCategory($categoryId) !== null
			&& $permissions->entityType()->canAddItemsInCategory($entityTypeId, $categoryId)
		)
		{
			$item->setCategoryId($categoryId);
			$isChanged = true;
		}

		$stageId = (string)($context['stageId'] ?? '');
		$itemCategoryId = $item->isCategoriesSupported() ? (int)$item->getCategoryId() : null;
		if (
			$stageId !== ''
			&& $item->isStagesEnabled()
			&& (string)$item->getStageId() !== $stageId
			&& $this->isStageOfCategory($factory, $itemCategoryId, $stageId)
			&& $permissions->stage()->canAddInStage($entityTypeId, $itemCategoryId, $stageId)
		)
		{
			$item->setStageId($stageId);
			$isChanged = true;
		}

		return $isChanged;
	}

	/**
	 * The client of a smart process is its contact and company rather than a parent field, so they are set
	 * the way the card sets them. Contacts the form has bound itself are kept.
	 */
	private function bindParent(Factory $factory, Item $item, int $parentTypeId, int $parentId): bool
	{
		if ($parentTypeId === \CCrmOwnerType::Contact)
		{
			if (!$factory->isFieldExists(Item::FIELD_NAME_CONTACT_IDS))
			{
				return false;
			}

			$contactIds = EntityBinding::prepareEntityIDs(\CCrmOwnerType::Contact, $item->getContactBindings());
			if (in_array($parentId, $contactIds, true))
			{
				return false;
			}

			$item->bindContacts(EntityBinding::prepareEntityBindings(\CCrmOwnerType::Contact, [$parentId]));

			return true;
		}

		$fieldName = $parentTypeId === \CCrmOwnerType::Company
			? Item::FIELD_NAME_COMPANY_ID
			: ParentFieldManager::getParentFieldName($parentTypeId)
		;
		if (!$factory->isFieldExists($fieldName) || (int)$item->get($fieldName) === $parentId)
		{
			return false;
		}

		$item->set($fieldName, $parentId);

		return true;
	}

	/**
	 * The stage is asked of the funnel the item ends up in: when the funnel of the context was not
	 * applied, its stage would take the item out of the funnel the form put it into.
	 */
	private function isStageOfCategory(Factory $factory, ?int $categoryId, string $stageId): bool
	{
		foreach ($factory->getStages($categoryId) as $stage)
		{
			if ((string)$stage->getStatusId() === $stageId)
			{
				return true;
			}
		}

		return false;
	}

	private function getFactory(int $entityTypeId): ?Factory
	{
		return Container::getInstance()->getFactory($entityTypeId);
	}
}
