<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Result;
use Bitrix\Crm\Search\Result\Factory as SearchResultFactory;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserField\Types;
use Bitrix\Main\UserField\Types\EnumType;
use Throwable;

final class ItemService
{
	public const DEFAULT_LIST_LIMIT = 50;
	public const MAX_MOVE_LIMIT = 100;
	private const AMOUNT_CACHE_TTL = 3600;

	private const EDITABLE_USERFIELD_TYPES = [
		Types\StringType::USER_TYPE_ID => true,
		Types\IntegerType::USER_TYPE_ID => true,
		Types\DoubleType::USER_TYPE_ID => true,
		Types\EnumType::USER_TYPE_ID => true,
	];

	public function __construct(
		private readonly CategoryService $categoryService,
	)
	{
	}

	public function createItem(
		int $userId,
		int $entityTypeId,
		?int $categoryId,
		string $title,
	): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		$categoryId = $categoryNormalizeResult->get('categoryId');
		$item = $factory->createItem();

		if ($categoryId !== null && $item->hasField(Item::FIELD_NAME_CATEGORY_ID))
		{
			$item->setCategoryId($categoryId);
		}

		if ($item->hasField(Item::FIELD_NAME_TITLE))
		{
			$item->setTitle($title);
		}
		elseif ($item->hasField(Item::FIELD_NAME_NAME))
		{
			$item->setName($title);
		}

		$permissions = Container::getInstance()->getUserPermissions($userId)->item();
		if (!$permissions->canAddItem($item))
		{
			return Result::failAccessDenied();
		}

		$result = $this->addItem($userId, $item);
		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		return Result::success(item: Response\Item::fromItem($item));
	}

	public function listItems(
		int $userId,
		int $entityTypeId,
		?string $query,
		array $filter = [],
		?int $limit = self::DEFAULT_LIST_LIMIT,
		?string $stageId = null,
	): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}
		if ($stageId !== null && !$factory->isStagesSupported())
		{
			return Result::fail("Stages are not supported for entityTypeId {$entityTypeId}.");
		}
		if ($stageId !== null && !$factory->isStagesEnabled())
		{
			return Result::fail("Stages are not enabled for entityTypeId {$entityTypeId}.");
		}

		$limit = $limit ?? self::DEFAULT_LIST_LIMIT;

		$itemIds = $this->findItemIds($factory, $userId, $query, $filter, $limit);
		if (empty($itemIds))
		{
			return Result::success(items: []);
		}

		$items = $factory->getItems([
			'select' => $this->buildSelect($factory),
			'filter' => [
				'@' . Item::FIELD_NAME_ID => $itemIds,
			],
			'limit' => $limit,
		]);

		$resultItems = [];
		foreach ($items as $item)
		{
			$resultItems[] = Response\Item::fromItem($item);
		}

		return Result::success(items: $resultItems);
	}

	public function getItemsAmount(
		int $userId,
		int $entityTypeId,
		?string $stageId,
		array $filter,
	): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}
		if (!$factory->isStagesSupported())
		{
			return Result::fail("Stages are not supported for entityTypeId {$entityTypeId}.");
		}
		if (!$factory->isStagesEnabled())
		{
			return Result::fail("Stages are not enabled for entityTypeId {$entityTypeId}.");
		}

		if (
			!$factory->getFieldsCollection()->hasField(Item::FIELD_NAME_OPPORTUNITY_ACCOUNT)
			|| !$factory->getFieldsCollection()->hasField(Item::FIELD_NAME_STAGE_ID)
		)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$stage = $factory->getStage($stageId);
		if ($stage === null)
		{
			return Result::fail(
				"Stage '{$stageId}' was not found for entityTypeId {$entityTypeId}. Use list_stages to get available stage IDs."
			);
		}

		$isAllowed = Container::getInstance()
			->getUserPermissions($userId)
			->kanban()
			->canReadKanbanSumInStage($entityTypeId, $stage->getCategoryId(), $stage->getStatusId())
		;

		if (!$isAllowed)
		{
			return Result::failAccessDenied();
		}

		$amount = (float)$factory->getItemsOpportunityAccountAmount($filter, self::AMOUNT_CACHE_TTL);

		return Result::success(amount: new Response\Amount($amount));
	}

	public function moveItemsBetweenCategories(
		int $userId,
		int $entityTypeId,
		int $sourceCategoryId,
		int $targetCategoryId,
	): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$categoryValidationResult = $this->categoryService->validateEntityTypeWithCategories($entityTypeId, $factory);
		if (!$categoryValidationResult->isSuccess())
		{
			return $categoryValidationResult;
		}

		$sourceCategory = $factory->getCategory($sourceCategoryId);
		if ($sourceCategory === null)
		{
			return Result::fail(
				"Source category with ID {$sourceCategoryId} was not found for entityTypeId {$entityTypeId}. Use list_categories to get available category IDs."
			);
		}

		$targetCategory = $factory->getCategory($targetCategoryId);
		if ($targetCategory === null)
		{
			return Result::fail(
				"Target category with ID {$targetCategoryId} was not found for entityTypeId {$entityTypeId}. Use list_categories to get available category IDs."
			);
		}

		$filter = [
			'=' . Item::FIELD_NAME_CATEGORY_ID => $sourceCategoryId,
		];

		$items = $factory->getItemsFilteredByPermissions([
			'select' => ['*'],
			'filter' => $filter,
			'order' => [
				Item::FIELD_NAME_ID => 'DESC',
			],
			'limit' => self::MAX_MOVE_LIMIT,
		], $userId, UserPermissions::OPERATION_UPDATE);

		$updatedCount = 0;
		$overallResult = new Result();
		foreach ($items as $item)
		{
			$item->setCategoryId($targetCategoryId);

			$updateResult = $this->updateItem($userId, $item);
			if ($updateResult->isSuccess())
			{
				$updatedCount++;
			}
			else
			{
				$overallResult->addErrors($updateResult->getErrors());
			}
		}

		if (!$overallResult->isSuccess())
		{
			return Result::fail($overallResult->getErrorCollection());
		}

		$leftCount = $factory->getItemsCountFilteredByPermissions($filter, $userId, UserPermissions::OPERATION_UPDATE);

		return Result::success(updatedCount: $updatedCount, leftCount: $leftCount);
	}

	public function moveItemsBetweenStages(
		int $userId,
		int $entityTypeId,
		string $sourceStageId,
		string $targetStageId,
	): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}
		if (!$factory->isStagesSupported())
		{
			return Result::fail("Stages are not supported for entityTypeId {$entityTypeId}.");
		}
		if (!$factory->isStagesEnabled())
		{
			return Result::fail("Stages are not enabled for entityTypeId {$entityTypeId}.");
		}

		$sourceStage = $factory->getStage($sourceStageId);
		if ($sourceStage === null)
		{
			return Result::fail(
				"Source stage '{$sourceStageId}' was not found for entityTypeId {$entityTypeId}. Use list_stages to get available stage IDs."
			);
		}

		$targetStage = $factory->getStageFromCategory($sourceStage->getCategoryId(), $targetStageId);
		if ($targetStage === null)
		{
			return Result::fail(
				"Target stage '{$targetStageId}' was not found in category {$sourceStage->getCategoryId()} for entityTypeId {$entityTypeId}. Use list_stages with that categoryId."
			);
		}

		$filter = [
			'=' . Item::FIELD_NAME_STAGE_ID => $sourceStageId,
		];

		$items = $factory->getItemsFilteredByPermissions([
			'select' => ['*'],
			'filter' => $filter,
			'order' => [
				Item::FIELD_NAME_ID => 'DESC',
			],
			'limit' => self::MAX_MOVE_LIMIT,
		], $userId, UserPermissions::OPERATION_UPDATE);

		$updatedCount = 0;
		$overallResult = new Result();
		foreach ($items as $item)
		{
			$item->setStageId($targetStage->getStatusId());
			if ($item->hasField(Item::FIELD_NAME_STAGE_SEMANTIC_ID))
			{
				$item->setStageSemanticId($targetStage->getSemantics() ?? PhaseSemantics::PROCESS);
			}

			$updateResult = $this->updateItem($userId, $item);
			if ($updateResult->isSuccess())
			{
				$updatedCount++;
			}
			else
			{
				$overallResult->addErrors($updateResult->getErrors());
			}
		}

		if (!$overallResult->isSuccess())
		{
			return Result::fail($overallResult->getErrorCollection());
		}

		$leftCount = $factory->getItemsCountFilteredByPermissions($filter, $userId, UserPermissions::OPERATION_UPDATE);

		return Result::success(updatedCount: $updatedCount, leftCount: $leftCount);
	}

	public function listUserFields(int $userId, int $entityTypeId, bool $checkPermissions = true): Result
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		if ($checkPermissions)
		{
			$permissions = Container::getInstance()->getUserPermissions($userId)->entityType();
			if (!$permissions->canReadItems($entityTypeId))
			{
				return Result::failAccessDenied();
			}
		}

		$responseFields = [];
		foreach ($factory->getUserFieldsCollection() as $userField)
		{
			if (!isset(self::EDITABLE_USERFIELD_TYPES[$userField->getType()]))
			{
				continue;
			}

			$responseField = Response\Field::fromField($userField);
			if ($userField->getType() === EnumType::USER_TYPE_ID)
			{
				$enumList = EnumType::getList($userField->getUserField());
				while ($item = $enumList->Fetch())
				{
					$enum = Response\Field\Enum::tryFromArray($item);
					if ($enum !== null)
					{
						$responseField->enum[] = $enum;
					}
				}
			}

			$responseFields[] = $responseField;
		}

		return Result::success(fields: $responseFields);
	}

	public function appendUserFieldValue(
		int $userId,
		int $entityTypeId,
		int $itemId,
		string $fieldId,
		mixed $fieldValue,
		bool $checkPermissions = true,
	): Result
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$field = $this->findUserField($entityTypeId, $fieldId);
		if ($field === null)
		{
			return Result::fail(
				"User field '{$fieldId}' was not found for entityTypeId {$entityTypeId}. Use list_item_user_fields to get available field IDs."
			);
		}

		$item = $factory->getItem(
			id: $itemId,
			fieldsToSelect: [
				Item::FIELD_NAME_ID,
				$field->getName(),
			],
		);
		if ($item === null)
		{
			return Result::fail("Item with ID {$itemId} was not found for entityTypeId {$entityTypeId}. Use list_items to find item IDs.");
		}

		if ($checkPermissions)
		{
			$permissions = Container::getInstance()->getUserPermissions($userId)->item();
			if (!$permissions->canUpdateItem($item))
			{
				return Result::failAccessDenied();
			}
		}

		$isValueEmpty = $field->isItemValueEmpty($item);
		$isMultiple = $field->isMultiple();

		if (!$isValueEmpty && !$isMultiple)
		{
			return Result::fail(
				"Field '{$fieldId}' already has a value on item {$itemId} and is not multiple. This tool can only append to an empty single field or to a multiple field."
			);
		}

		$normalizedValue = $fieldValue;
		if ($field->getType() === EnumType::USER_TYPE_ID)
		{
			$normalizedValue = $this->findEnumValueId($field, $fieldValue);
			if ($normalizedValue === null)
			{
				return Result::fail(
					"Passed value is not a valid enum option for field '{$field->getName()}'. Use list_item_user_fields to get available enum values."
				);
			}
		}

		if ($isMultiple)
		{
			$currentValue = $item->get($field->getName());
			$currentValue = is_array($currentValue) ? $currentValue : [];

			if ($field->getType() === EnumType::USER_TYPE_ID)
			{
				$values[$field->getName()] = array_unique([
					...array_map(intval(...), $currentValue),
					(int)$normalizedValue,
				]);
			}
			else
			{
				$values[$field->getName()] = array_merge($currentValue, [$normalizedValue]);
			}
		}
		else
		{
			$values[$field->getName()] = $normalizedValue;
		}

		$item->setFromCompatibleData($values);

		$saveResult = $this->updateItem($userId, $item);
		if (!$saveResult->isSuccess())
		{
			return Result::fail($saveResult->getErrorCollection());
		}

		return Result::success();
	}

	public function updateItem(int $userId, Item $item): \Bitrix\Main\Result
	{
		$factory = Container::getInstance()->getFactory($item->getEntityTypeId());

		return $factory
			?->getUpdateOperation($item, $this->createContext($userId))
			// MCP updates only a single supported user field after service-level access and value validation.
			->disableAllChecks()
			->launch()
		;
	}

	public function addItem(int $userId, Item $item): \Bitrix\Main\Result
	{
		$factory = Container::getInstance()->getFactory($item->getEntityTypeId());

		return $factory
			?->getAddOperation($item, $this->createContext($userId))
			->disableCheckFields()
			// MCP creates minimal items by title, without forcing unrelated required user fields.
			->disableCheckRequiredUserFields()
			->launch()
		;
	}

	private function findUserField(int $entityTypeId, string $fieldIdOrName): ?Field
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return null;
		}

		foreach ($factory->getUserFieldsCollection() as $field)
		{
			if (!isset(self::EDITABLE_USERFIELD_TYPES[$field->getType()]))
			{
				continue;
			}

			$userField = $field->getUserField();
			$searchList = [
				$field->getName(),
				$field->getTitle(),
				$userField['EDIT_FORM_LABEL'] ?? null,
				$userField['LIST_COLUMN_LABEL'] ?? null,
				$userField['LIST_FILTER_LABEL'] ?? null,
				$userField['FIELD_NAME'] ?? null,
			];

			$searchList = array_flip($searchList);
			if (isset($searchList[$fieldIdOrName]))
			{
				return $field;
			}
		}

		return null;
	}

	public function buildFilter(
		int $entityTypeId,
		?int $categoryId = null,
		?string $stageId = null,
		?string $stageSemantic = null,
		?string $closedDateFrom = null,
		?string $closedDateTo = null,
	): array
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return [];
		}

		$filter = [];

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $categoryId);
		$categoryId = $categoryNormalizeResult->isSuccess()
			? $categoryNormalizeResult->get('categoryId')
			: null
		;
		if ($categoryId !== null)
		{
			$filter['=' . Item::FIELD_NAME_CATEGORY_ID] = $categoryId;
		}

		if ($stageId !== null && $factory->isStagesSupported())
		{
			$filter['=' . Item::FIELD_NAME_STAGE_ID] = $stageId;
		}

		if (
			$stageSemantic !== null
			&& $factory->getFieldsCollection()->hasField(Item::FIELD_NAME_STAGE_SEMANTIC_ID)
		)
		{
			$filter['=' . Item::FIELD_NAME_STAGE_SEMANTIC_ID] = $stageSemantic;
		}
		elseif ($stageSemantic !== null && $factory->isStagesSupported())
		{
			$stageIds = [];
			foreach ($factory->getStages($categoryId) as $stage)
			{
				$semantics = $stage->getSemantics();
				$semantics = PhaseSemantics::isDefined($semantics) ? $semantics : PhaseSemantics::PROCESS;
				if ($semantics === $stageSemantic)
				{
					$stageIds[] = $stage->getStatusId();
				}
			}

			if (empty($stageIds))
			{
				$filter['=' . Item::FIELD_NAME_ID] = 0;
			}
			else
			{
				$filter['@' . Item::FIELD_NAME_STAGE_ID] = $stageIds;
			}
		}

		if ($factory->getFieldsCollection()->hasField(Item::FIELD_NAME_CLOSE_DATE))
		{
			if ($closedDateFrom !== null)
			{
				$date = DateTime::tryParse($closedDateFrom, 'Y-m-d');
				if ($date !== null)
				{
					$filter['>=' . Item::FIELD_NAME_CLOSE_DATE] = $date;
				}
			}

			if ($closedDateTo !== null)
			{
				$date = DateTime::tryParse($closedDateTo, 'Y-m-d');
				if ($date !== null)
				{
					$filter['<=' . Item::FIELD_NAME_CLOSE_DATE] = $date->setTime(23, 59, 59);
				}
			}
		}

		return $filter;
	}

	private function findItemIds(
		Factory $factory,
		int $userId,
		?string $query,
		array $filter,
		int $limit,
	): array
	{
		if ($query !== null && $query !== '')
		{
			try
			{
				$provider = SearchResultFactory::createProvider($factory->getEntityTypeId());
				$provider->setUserId($userId);
				$provider->setLimit($limit);
				if (!empty($filter))
				{
					$provider->setAdditionalFilter($filter);
				}

				return $provider->getSearchResult($query)->getIds();
			}
			catch (Throwable)
			{
				return [];
			}
		}

		$items = $factory->getItemsFilteredByPermissions([
			'select' => [
				Item::FIELD_NAME_ID,
			],
			'filter' => $filter,
			'limit' => $limit,
			'order' => [
				Item::FIELD_NAME_ID => 'DESC',
			],
		], $userId);

		return array_map(static fn(Item $item) => $item->getId(), $items);
	}

	private function getFactory(int $entityTypeId): ?Factory
	{
		return Container::getInstance()->getFactory($entityTypeId);
	}

	private function createContext(int $userId): Context
	{
		return (new Context())
			->setUserId($userId)
			->setScope(Context::SCOPE_AI)
		;
	}

	private function findEnumValueId(Field $field, string $value): ?string
	{
		$enumResult = EnumType::getList($field->getUserField());
		while ($enum = $enumResult->Fetch())
		{
			if ($value === (string)$enum['ID'] || $value === (string)$enum['VALUE'])
			{
				return $enum['ID'];
			}
		}

		return null;
	}

	private function buildSelect(Factory $factory): array
	{
		$select = [
			Item::FIELD_NAME_ID,
		];

		$fields = $factory->getFieldsCollection();
		if ($fields->hasField(Item::FIELD_NAME_TITLE))
		{
			$select[] = Item::FIELD_NAME_TITLE;
		}

		if ($fields->hasField(Item::FIELD_NAME_STAGE_ID))
		{
			$select[] = Item::FIELD_NAME_STAGE_ID;
		}

		if ($fields->hasField(Item::FIELD_NAME_CATEGORY_ID))
		{
			$select[] = Item::FIELD_NAME_CATEGORY_ID;
		}

		if (
			$fields->hasField(Item::FIELD_NAME_NAME)
			&& $fields->hasField(Item::FIELD_NAME_LAST_NAME)
			&& $fields->hasField(Item::FIELD_NAME_SECOND_NAME)
		)
		{
			$select[] = Item::FIELD_NAME_NAME;
			$select[] = Item::FIELD_NAME_LAST_NAME;
			$select[] = Item::FIELD_NAME_SECOND_NAME;
		}

		return $select;
	}
}
