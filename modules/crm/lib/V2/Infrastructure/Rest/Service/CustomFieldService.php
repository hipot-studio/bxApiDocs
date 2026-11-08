<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Service;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory\Dynamic;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\UserField\Access as CrmUserFieldAccess;
use Bitrix\Crm\UserField\UserFieldHistory;
use Bitrix\Crm\UserField\Visibility\VisibilityManager;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CustomFieldConfigDto;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Entity as OrmEntity;
use Bitrix\Main\UserFieldLangTable;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityAlreadyExistsException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use CCrmFields;
use CCrmOwnerType;
use CUserTypeEntity;

final class CustomFieldService
{
	private const LABEL_FIELDS = [
		'EDIT_FORM_LABEL',
		'LIST_COLUMN_LABEL',
		'LIST_FILTER_LABEL',
		'ERROR_MESSAGE',
		'HELP_MESSAGE',
	];

	private CategoryUserFieldFilter $categoryUserFieldFilter;

	public function __construct(?CategoryUserFieldFilter $categoryUserFieldFilter = null)
	{
		$this->categoryUserFieldFilter = $categoryUserFieldFilter ?? new CategoryUserFieldFilter();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getList(
		EntityType $entityType,
		string $routeEntityId,
		int $userId,
		string $language,
		?FilterStructure $filter = null,
		?OrderStructure $order = null,
		?PaginationStructure $pagination = null,
	): array
	{
		$context = $this->getContext($entityType, $routeEntityId);

		$fields = $this->getFields($entityType, $context['entityId'], $language);
		$fields = VisibilityManager::getVisibleUserFields($fields, $userId);
		if (!$this->canReadEntityConfiguration($context['entityId'], $userId))
		{
			return [];
		}
		$fields = array_values($fields);
		$fields = $this->applyFilter($fields, $filter);
		$fields = $this->applyOrder($fields, $order);

		return $this->applyPagination($fields, $pagination);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getById(
		EntityType $entityType,
		string $routeEntityId,
		int $id,
		int $userId,
		string $language,
	): array
	{
		$context = $this->getContext($entityType, $routeEntityId);

		$field = $this->findVisibleFieldById(
			$this->getFields($entityType, $context['entityId'], $language),
			$id,
			$userId,
		);
		if ($field === null)
		{
			throw new \Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException((string)$id);
		}

		return $field;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public function add(
		EntityType $entityType,
		string $routeEntityId,
		int $userId,
		array $fields,
		string $language,
	): array
	{
		$context = $this->getContext($entityType, $routeEntityId);
		$fields['ENTITY_ID'] = $context['entityId'];
		$this->assertSupportedType($fields['USER_TYPE_ID'] ?? null);
		$this->assertFieldName($fields['FIELD_NAME'] ?? null);

		$access = new CrmUserFieldAccess($userId);
		if (!$access->canAdd($fields))
		{
			throw new AccessDeniedException();
		}

		$this->assertFieldNameIsFree($entityType, $context['entityId'], (string)$fields['FIELD_NAME']);
		$fields = $this->applyAddDefaults($access, $fields);
		$fields = $this->prepareSettings($fields);

		$crmFields = $this->getCrmFields($context['entityId']);
		if (!$crmFields->AddField($fields))
		{
			$this->throwUserFieldOperationError('add', (string)$fields['FIELD_NAME']);
		}
		$this->clearFactoryFieldsCache($context['entityTypeId']);

		$created = $this->findFieldByName(
			$this->getFields($entityType, $context['entityId'], $language),
			(string)$fields['FIELD_NAME'],
		);
		if ($created === null)
		{
			throw new EntityNotFoundException(0);
		}

		return $created;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public function update(
		EntityType $entityType,
		string $routeEntityId,
		int $id,
		int $userId,
		array $fields,
		string $language,
	): array
	{
		$context = $this->getContext($entityType, $routeEntityId);
		$current = $this->findVisibleFieldById(
			$this->getFields($entityType, $context['entityId'], $language),
			$id,
			$userId,
		);
		if ($current === null)
		{
			throw new \Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException((string)$id);
		}

		$this->assertUpdateFields($fields);
		$access = new CrmUserFieldAccess($userId);
		if (!$access->canUpdate($id))
		{
			throw new AccessDeniedException();
		}

		$fields['ENTITY_ID'] = $context['entityId'];
		$fields['USER_TYPE_ID'] = $current['USER_TYPE_ID'];
		$fields = $this->mergeExistingLabels($id, $fields);
		$fields = $this->prepareSettings($fields, $id);

		if ($entityType->isSmartProcess())
		{
			if (!(new CUserTypeEntity())->Update($id, $fields))
			{
				$this->throwUserFieldOperationError('update', (string)$id);
			}

			UserFieldHistory::processModification($entityType->getId(), $id);
		}
		else
		{
			if (!$this->getCrmFields($context['entityId'])->UpdateField($id, $fields))
			{
				$this->throwUserFieldOperationError('update', (string)$id);
			}
		}
		$this->clearFactoryFieldsCache($context['entityTypeId']);

		$updated = $this->findFieldById(
			$this->getFields($entityType, $context['entityId'], $language),
			$id,
		);
		if ($updated === null)
		{
			throw new EntityNotFoundException($id);
		}

		return $updated;
	}

	public function delete(
		EntityType $entityType,
		string $routeEntityId,
		int $id,
		int $userId,
	): void
	{
		$context = $this->getContext($entityType, $routeEntityId);
		$field = $this->findVisibleFieldById(
			$this->getFields(
				$entityType,
				$context['entityId'],
				defined('LANGUAGE_ID') ? LANGUAGE_ID : 'en',
			),
			$id,
			$userId,
		);
		if ($field === null)
		{
			throw new \Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException((string)$id);
		}

		$access = new CrmUserFieldAccess($userId);
		if (!$access->canDelete($id))
		{
			throw new AccessDeniedException();
		}

		if ($entityType->isSmartProcess())
		{
			if (!(new CUserTypeEntity())->Delete($id))
			{
				$this->throwUserFieldOperationError('delete', (string)$id);
			}

			UserFieldHistory::processRemoval($entityType->getId(), $id);
			$this->clearFactoryFieldsCache($context['entityTypeId']);

			return;
		}

		if (!$this->getCrmFields($context['entityId'])->DeleteField($id))
		{
			$this->throwUserFieldOperationError('delete', (string)$id);
		}
		$this->clearFactoryFieldsCache($context['entityTypeId']);
	}

	private function clearFactoryFieldsCache(int $entityTypeId): void
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return;
		}

		$factory->clearFieldsCollectionCache();
		if ($factory instanceof Dynamic)
		{
			$dataClass = $factory->getDataClass();
			OrmEntity::destroy($dataClass::getEntity());
			\Bitrix\Crm\Model\Dynamic\TypeTable::compileEntity($factory->getType());
		}
	}

	/**
	 * @return array{entityTypeId: int, entityId: string}
	 */
	private function getContext(EntityType $entityType, string $routeEntityId): array
	{
		$entityTypeId = $entityType->getId();
		$factory = Container::getInstance()->getFactory($entityTypeId);
		$entityId = $factory?->getUserFieldEntityId() ?? CCrmOwnerType::ResolveUserFieldEntityID($entityTypeId);

		if ($factory === null || $entityId === '' || $entityId !== $routeEntityId)
		{
			throw new EntityNotFoundException($entityTypeId);
		}

		return [
			'entityTypeId' => $entityTypeId,
			'entityId' => $entityId,
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function getFields(EntityType $entityType, string $entityId, string $language): array
	{
		global $USER_FIELD_MANAGER;

		$fields = $this->runLegacyReadSafely(
			fn() => $this->getCrmFields($entityId)->GetFields(),
		);
		if (!is_array($fields))
		{
			return [];
		}

		$language = $language !== '' ? $language : (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'en');
		$localizedFields = $this->runLegacyReadSafely(
			fn() => $USER_FIELD_MANAGER->GetUserFields($entityId, 0, $language),
		);
		if (!is_array($localizedFields))
		{
			return $this->categoryUserFieldFilter->filter($entityType, 0, $fields);
		}

		if ($fields === [])
		{
			$fields = $localizedFields;
		}
		else
		{
			foreach ($fields as $fieldId => $field)
			{
				if (isset($localizedFields[$fieldId]))
				{
					$fields[$fieldId] = $localizedFields[$fieldId];
				}
			}
		}

		return $this->categoryUserFieldFilter->filter($entityType, 0, $fields);
	}

	private function runLegacyReadSafely(callable $read): mixed
	{
		try
		{
			return $read();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function getCrmFields(string $entityId): CCrmFields
	{
		global $USER_FIELD_MANAGER;

		return new CCrmFields($USER_FIELD_MANAGER, $entityId);
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function findVisibleFieldById(array $fields, int $id, int $userId): ?array
	{
		$field = $this->findFieldById($fields, $id);
		if ($field === null)
		{
			return null;
		}

		$visibleFields = VisibilityManager::getVisibleUserFields([$id => $field], $userId);
		$visibleFields = $this->filterReadableFields($visibleFields, $userId);

		return $this->findFieldById($visibleFields, $id);
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 * @return array<string, array<string, mixed>>
	 */
	private function filterReadableFields(array $fields, int $userId): array
	{
		$access = new CrmUserFieldAccess($userId);

		return array_filter(
			$fields,
			fn(array $field): bool => $access->canRead((int)($field['ID'] ?? 0)),
		);
	}

	private function canReadEntityConfiguration(string $entityId, int $userId): bool
	{
		$configuration = Container::getInstance()->getUserPermissions($userId)->configuration();
		$scope = ConfigurationScope::ofUserFieldEntityId($entityId);

		return $configuration->canManageFields(ConfigurationCallSite::UserFieldAccess, $scope)
			|| $configuration->canDeleteFields(ConfigurationCallSite::UserFieldAccess, $scope);
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function findFieldById(array $fields, int $id): ?array
	{
		foreach ($fields as $field)
		{
			if ((int)($field['ID'] ?? 0) === $id)
			{
				return $field;
			}
		}

		return null;
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function findFieldByName(array $fields, string $name): ?array
	{
		foreach ($fields as $field)
		{
			if (($field['FIELD_NAME'] ?? null) === $name)
			{
				return $field;
			}
		}

		return null;
	}

	private function assertSupportedType(mixed $type): void
	{
		if (!is_string($type) || !in_array($type, CustomFieldConfigDto::SUPPORTED_USER_TYPE_IDS, true))
		{
			throw new RequestValidationException([
				new Error('Unsupported user field type.', 'userTypeId'),
			]);
		}
	}

	private function assertFieldName(mixed $name): void
	{
		if (!is_string($name) || $name === '')
		{
			throw new RequestValidationException([
				new Error('The field name is required.', 'name'),
			]);
		}
	}

	private function assertFieldNameIsFree(EntityType $entityType, string $entityId, string $name): void
	{
		if ($this->findFieldByName($this->getFields($entityType, $entityId, ''), $name) !== null)
		{
			throw new EntityAlreadyExistsException($name);
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function assertUpdateFields(array $fields): void
	{
		foreach (['ID', 'ENTITY_ID', 'FIELD_NAME', 'USER_TYPE_ID', 'MULTIPLE'] as $fieldName)
		{
			if (array_key_exists($fieldName, $fields))
			{
				throw new RequestValidationException([
					new Error('The field is immutable.', $fieldName),
				]);
			}
		}

		if ($fields === [])
		{
			throw new RequestValidationException([
				new Error('At least one field must be specified.', 'fields'),
			]);
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function mergeExistingLabels(int $id, array $fields): array
	{
		$hasLabelUpdate = false;
		foreach (self::LABEL_FIELDS as $labelField)
		{
			if (array_key_exists($labelField, $fields) && is_array($fields[$labelField]))
			{
				$hasLabelUpdate = true;
				break;
			}
		}

		if (!$hasLabelUpdate)
		{
			return $fields;
		}

		$existingLabels = $this->getExistingLabels($id);
		foreach (self::LABEL_FIELDS as $labelField)
		{
			$fields[$labelField] = array_replace(
				$existingLabels[$labelField] ?? [],
				is_array($fields[$labelField] ?? null) ? $fields[$labelField] : [],
			);
		}

		return $fields;
	}

	/**
	 * @return array<string, array<string, string|null>>
	 */
	private function getExistingLabels(int $id): array
	{
		$labels = array_fill_keys(self::LABEL_FIELDS, []);
		$rows = UserFieldLangTable::getList([
			'filter' => ['=USER_FIELD_ID' => $id],
			'select' => array_merge(['LANGUAGE_ID'], self::LABEL_FIELDS),
		])->fetchAll();

		foreach ($rows as $row)
		{
			$languageId = (string)$row['LANGUAGE_ID'];
			foreach (self::LABEL_FIELDS as $labelField)
			{
				$labels[$labelField][$languageId] = $row[$labelField] ?? null;
			}
		}

		return $labels;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function applyAddDefaults(CrmUserFieldAccess $access, array $fields): array
	{
		if (array_key_exists('SETTINGS', $fields) && !is_array($fields['SETTINGS']))
		{
			return $fields;
		}

		$defaultSettings = $access->getDefaultSettings($fields);
		if (empty($defaultSettings))
		{
			return $fields;
		}

		$fields['SETTINGS'] = array_merge($defaultSettings, $fields['SETTINGS'] ?? []);

		return $fields;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function prepareSettings(array $fields, int $id = 0): array
	{
		global $USER_FIELD_MANAGER;

		if ($id > 0 && !array_key_exists('SETTINGS', $fields))
		{
			return $fields;
		}

		if (!array_key_exists('SETTINGS', $fields))
		{
			$fields['SETTINGS'] = [];
		}
		elseif (!is_array($fields['SETTINGS']))
		{
			throw new RequestValidationException([
				new Error('Invalid user field settings.', 'settings'),
			]);
		}

		try
		{
			$preparedSettings = $USER_FIELD_MANAGER->PrepareSettings($id, $fields);
		}
		catch (\Throwable)
		{
			throw new RequestValidationException([
				new Error('Invalid user field settings.', 'settings'),
			]);
		}

		if (!is_array($preparedSettings))
		{
			throw new RequestValidationException([
				new Error('Invalid user field settings.', 'settings'),
			]);
		}

		$fields['SETTINGS'] = $preparedSettings;

		return $fields;
	}

	private function throwUserFieldOperationError(string $operation, string $identifier): never
	{
		$application = $GLOBALS['APPLICATION'] ?? null;
		$applicationException = is_object($application) ? $application->GetException() : null;
		$message = 'Unable to ' . $operation . ' user field ' . $identifier . '.';
		if ($applicationException instanceof \Throwable)
		{
			$message = $applicationException->getMessage();
		}
		elseif (is_object($applicationException) && method_exists($applicationException, 'GetString'))
		{
			$message = (string)$applicationException->GetString();
		}

		throw new RequestValidationException([
			new Error($message, 'field'),
		]);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	private function applyFilter(array $items, ?FilterStructure $filter): array
	{
		if ($filter === null || !$this->hasEffectiveConditions($filter))
		{
			return $items;
		}

		return array_values(array_filter(
			$items,
			fn(array $item): bool => $this->matchesFilter($item, $filter),
		));
	}

	private function hasEffectiveConditions(FilterStructure $filter): bool
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof Condition)
			{
				return true;
			}
			if ($condition instanceof FilterStructure && $this->hasEffectiveConditions($condition))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function matchesFilter(array $item, FilterStructure $filter): bool
	{
		$conditions = $filter->getConditions();
		$result = $filter->logic() === Logic::And;

		foreach ($conditions as $condition)
		{
			$matches = $condition instanceof FilterStructure
				? (!$this->hasEffectiveConditions($condition) || $this->matchesFilter($item, $condition))
				: $this->matchesCondition($item, $condition);

			if ($filter->logic() === Logic::And && !$matches)
			{
				$result = false;
				break;
			}
			if ($filter->logic() === Logic::Or && $matches)
			{
				$result = true;
				break;
			}
		}

		return $filter->isNegative() ? !$result : $result;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function matchesCondition(array $item, Condition $condition): bool
	{
		$left = $this->getFieldValue($item, (string)$condition->getLeftOperand());
		$right = $condition->getRightOperand();

		return match ($condition->getOperator())
		{
			Operator::Equal => $left === $right,
			Operator::NotEqual => $left !== $right,
			Operator::Greater => $left > $right,
			Operator::GreaterOrEqual => $left >= $right,
			Operator::Less => $left < $right,
			Operator::LessOrEqual => $left <= $right,
			Operator::In => is_array($right) && in_array($left, $right, true),
			Operator::Between => is_array($right) && count($right) === 2 && $left >= $right[0] && $left <= $right[1],
		};
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function getFieldValue(array $item, string $field): mixed
	{
		return match ($field)
		{
			'id' => (int)($item['ID'] ?? 0),
			'entityId' => $item['ENTITY_ID'] ?? null,
			'name' => $item['FIELD_NAME'] ?? null,
			'userTypeId' => $item['USER_TYPE_ID'] ?? null,
			'sort' => (int)($item['SORT'] ?? 0),
			default => null,
		};
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	private function applyOrder(array $items, ?OrderStructure $order): array
	{
		if ($order === null || $order->getItems() === [])
		{
			return $items;
		}

		usort($items, function (array $left, array $right) use ($order): int
		{
			foreach ($order->getItems() as $orderItem)
			{
				$result = $this->getFieldValue($left, $orderItem->getProperty())
					<=> $this->getFieldValue($right, $orderItem->getProperty());
				if ($result !== 0)
				{
					return $orderItem->getOrder() === \Bitrix\Main\DB\Order::Desc ? -$result : $result;
				}
			}

			return 0;
		});

		return $items;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	private function applyPagination(array $items, ?PaginationStructure $pagination): array
	{
		if ($pagination === null)
		{
			return $items;
		}

		return array_values(array_slice($items, $pagination->getOffset(), $pagination->getLimit()));
	}
}
