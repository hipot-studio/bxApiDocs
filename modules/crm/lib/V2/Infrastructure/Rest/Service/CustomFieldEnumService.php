<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Service;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\UserField\Visibility\VisibilityManager;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\CustomFieldEnumException;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Main\Application;
use Bitrix\Main\Security\Random;
use CUserFieldEnum;

final class CustomFieldEnumService
{
	private const USER_TYPE_ID = 'enumeration';

	private const FILTER_FIELDS = [
		'fieldId' => 'USER_FIELD_ID',
		'id' => 'ID',
		'sort' => 'SORT',
	];

	private CUserFieldEnum $enum;
	private CategoryUserFieldFilter $categoryUserFieldFilter;

	public function __construct(
		?CUserFieldEnum $enum = null,
		?CategoryUserFieldFilter $categoryUserFieldFilter = null,
	)
	{
		$this->enum = $enum ?? new CUserFieldEnum();
		$this->categoryUserFieldFilter = $categoryUserFieldFilter ?? new CategoryUserFieldFilter();
	}

	public function list(
		EntityType $entityType,
		int $userId,
		FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): array
	{
		$this->checkEntityRead($entityType, $userId);
		$fieldId = $filter->getSimpleFilterConditions()['fieldId'] ?? null;
		if (!is_int($fieldId) || $fieldId <= 0)
		{
			throw new InvalidFilterException($filter->getList());
		}

		$field = $this->getField($entityType, $userId, $fieldId);
		$items = array_values(array_filter(
			$this->getEnumValues((int)$field['ID']),
			fn(array $item): bool => $this->matchesFilter($item, $filter),
		));
		$this->sort($items, $order);

		if ($pagination === null)
		{
			return $items;
		}

		return array_slice($items, $pagination->getOffset(), $pagination->getLimit());
	}

	public function get(EntityType $entityType, int $userId, int $id): array
	{
		$this->checkEntityRead($entityType, $userId);
		$value = $this->getEnumValue($id);
		$field = $this->getField($entityType, $userId, (int)$value['USER_FIELD_ID']);
		if ((int)$value['USER_FIELD_ID'] !== (int)$field['ID'])
		{
			throw new FieldNotFoundException((string)(int)$value['USER_FIELD_ID']);
		}

		return $value;
	}

	public function add(EntityType $entityType, int $userId, int $fieldId, array $values): array
	{
		$this->checkEntityRead($entityType, $userId);
		$this->checkManage($entityType, $userId);
		$field = $this->getField($entityType, $userId, $fieldId, true);
		$preserveXmlId = isset($values['XML_ID']) && (string)$values['XML_ID'] === '0';
		$lookupXmlId = $values['XML_ID'] ?? null;
		if ($lookupXmlId === null || (string)$lookupXmlId === '')
		{
			$lookupXmlId = 'crm_rest_enum_' . Random::getString(32);
			$values['XML_ID'] = $lookupXmlId;
		}
		if ($preserveXmlId)
		{
			$this->assertXmlIdIsAvailable((int)$field['ID'], '0');
			$lookupXmlId = $values['XML_ID'] = 'crm_rest_enum_' . Random::getString(32);
		}
		$payload = $this->getEnumValuesPayload((int)$field['ID']);
		$payload['n0'] = $values;
		$this->setValues((int)$field['ID'], $payload);

		$created = $this->getEnumValues((int)$field['ID'], null, ['XML_ID' => $lookupXmlId])[0] ?? null;
		if ($created === null)
		{
			throw new EntityNotFoundException($fieldId);
		}
		$createdId = (int)$created['ID'];
		if ($preserveXmlId)
		{
			$this->setXmlId($createdId, '0');
		}

		$created = $this->getEnumValue($createdId);
		if ((int)$created['USER_FIELD_ID'] !== (int)$field['ID'])
		{
			throw new EntityNotFoundException($fieldId);
		}

		return $created;
	}

	public function update(EntityType $entityType, int $userId, int $id, array $values): array
	{
		$this->checkEntityRead($entityType, $userId);
		$this->checkManage($entityType, $userId);
		$current = $this->getEnumValue($id);
		$this->getField($entityType, $userId, (int)$current['USER_FIELD_ID'], true);
		$updated = array_merge($current, $values);
		$preserveXmlId = isset($updated['XML_ID']) && (string)$updated['XML_ID'] === '0';
		if ($preserveXmlId)
		{
			$this->assertXmlIdIsAvailable((int)$current['USER_FIELD_ID'], '0', $id);
			$updated['XML_ID'] = 'crm_rest_enum_' . Random::getString(32);
		}
		unset($updated['ID'], $updated['USER_FIELD_ID']);
		$payload = $this->getEnumValuesPayload((int)$current['USER_FIELD_ID']);
		$payload[$id] = $updated;
		$this->setValues((int)$current['USER_FIELD_ID'], $payload);
		if ($preserveXmlId)
		{
			$this->setXmlId($id, '0');
		}

		return $this->getEnumValue($id);
	}

	public function delete(EntityType $entityType, int $userId, int $id): bool
	{
		$this->checkEntityRead($entityType, $userId);
		$this->checkManage($entityType, $userId);
		$current = $this->getEnumValue($id);
		$this->getField($entityType, $userId, (int)$current['USER_FIELD_ID'], true);
		$payload = $this->getEnumValuesPayload((int)$current['USER_FIELD_ID']);
		$payload[$id]['DEL'] = 'Y';
		$this->setValues((int)$current['USER_FIELD_ID'], $payload);

		return true;
	}

	private function checkEntityRead(EntityType $entityType, int $userId): void
	{
		if (!Container::getInstance()->getUserPermissions($userId)->entityType()->canReadItems($entityType->getId()))
		{
			throw new AccessDeniedException();
		}
	}

	private function checkManage(EntityType $entityType, int $userId): void
	{
		$permissions = Container::getInstance()->getUserPermissions($userId);
		if (!$permissions->configuration()->canManageFields(
			ConfigurationCallSite::FieldsEditComponent,
			ConfigurationScope::ofEntityType($entityType->getId()),
		))
		{
			throw new AccessDeniedException();
		}
	}

	private function getField(EntityType $entityType, int $userId, int $fieldId, bool $mutation = false): array
	{
		$entityId = \CCrmOwnerType::ResolveUserFieldEntityID($entityType->getId());
		$fields = Application::getUserTypeManager()->getUserFields(
			entity_id: $entityId,
			LANG: false,
			user_id: $userId,
		);
		if (!is_array($fields))
		{
			throw new FieldNotFoundException((string)$fieldId);
		}

		$fields = $this->categoryUserFieldFilter->filter($entityType, 0, $fields);
		$field = null;
		foreach ($fields as $candidate)
		{
			if ((int)($candidate['ID'] ?? 0) === $fieldId)
			{
				$field = $candidate;
				break;
			}
		}
		if ($field === null || ($field['USER_TYPE_ID'] ?? null) !== self::USER_TYPE_ID)
		{
			throw new FieldNotFoundException((string)$fieldId);
		}
		if (!isset($field['SETTINGS']) || !is_array($field['SETTINGS']))
		{
			$field['SETTINGS'] = [];
		}

		$visible = VisibilityManager::getVisibleUserFields([$fieldId => $field], $userId);
		if (!isset($visible[$fieldId]))
		{
			if ($mutation)
			{
				throw new AccessDeniedException();
			}
			throw new FieldNotFoundException((string)$fieldId);
		}

		return $field;
	}

	private function getEnumValue(int $id): array
	{
		$items = $this->getEnumValues(null, $id);
		if ($items === [])
		{
			throw new EntityNotFoundException($id);
		}

		return $items[0];
	}

	/**
	 * @return array<int|string, array<string, mixed>>
	 */
	private function getEnumValuesPayload(int $fieldId): array
	{
		$payload = [];
		foreach ($this->getEnumValues($fieldId) as $item)
		{
			$payload[(int)$item['ID']] = $item;
		}

		return $payload;
	}

	private function getEnumValues(
		?int $fieldId,
		?int $id = null,
		array $filter = [],
		array $order = [],
	): array
	{
		if ($fieldId !== null)
		{
			$filter['USER_FIELD_ID'] = $fieldId;
		}
		if ($id !== null)
		{
			$filter['ID'] = $id;
		}
		$result = $this->enum->GetList([], $filter);
		$items = [];
		while ($item = $result->Fetch())
		{
			if (is_array($item) && $this->isValidEnumValue($item))
			{
				$items[] = $item;
			}
		}
		if ($order !== [])
		{
			usort($items, static function (array $left, array $right) use ($order): int {
				foreach ($order as $field => $direction)
				{
					$comparison = ((int)($left[$field] ?? 0)) <=> ((int)($right[$field] ?? 0));
					if ($comparison !== 0)
					{
						return $direction === 'DESC' ? -$comparison : $comparison;
					}
				}

				return ((int)($left['ID'] ?? 0)) <=> ((int)($right['ID'] ?? 0));
			});
		}

		return $items;
	}

	private function isValidEnumValue(array $item): bool
	{
		return $this->isPositiveInteger($item['ID'] ?? null)
			&& $this->isPositiveInteger($item['USER_FIELD_ID'] ?? null)
			&& is_string($item['VALUE'] ?? null)
			&& $item['VALUE'] !== ''
			&& $this->isInteger($item['SORT'] ?? null)
			&& in_array($item['DEF'] ?? null, ['Y', 'N'], true)
			&& (!array_key_exists('XML_ID', $item) || $item['XML_ID'] === null || is_scalar($item['XML_ID']));
	}

	private function isPositiveInteger(mixed $value): bool
	{
		return $this->isInteger($value) && (int)$value > 0;
	}

	private function isInteger(mixed $value): bool
	{
		return is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false);
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	private function sort(array &$items, ?OrderStructure $order): void
	{
		$orderFields = [];
		foreach ($order?->getItems() ?? [] as $item)
		{
			$field = self::FILTER_FIELDS[$item->getProperty()] ?? null;
			if ($field === null)
			{
				throw new InvalidOrderException($item->getProperty());
			}

			$orderFields[$field] = $item->getOrder()->value;
		}
		if ($orderFields === [])
		{
			return;
		}

		$orderFields['ID'] ??= 'ASC';
		usort($items, static function (array $left, array $right) use ($orderFields): int {
			foreach ($orderFields as $field => $direction)
			{
				$comparison = ((int)($left[$field] ?? 0)) <=> ((int)($right[$field] ?? 0));
				if ($comparison !== 0)
				{
					return $direction === 'DESC' ? -$comparison : $comparison;
				}
			}

			return 0;
		});
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function matchesFilter(array $item, FilterStructure $filter): bool
	{
		$conditions = $filter->getConditions();
		if ($conditions === [])
		{
			throw new InvalidFilterException($filter->getList());
		}

		$matches = [];
		foreach ($conditions as $condition)
		{
			$matches[] = $condition instanceof FilterStructure
				? $this->matchesFilter($item, $condition)
				: $this->matchesCondition($item, $condition)
			;
		}

		$matched = $filter->logic() === Logic::And
			? !in_array(false, $matches, true)
			: in_array(true, $matches, true)
		;

		return $filter->isNegative() ? !$matched : $matched;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function matchesCondition(array $item, mixed $condition): bool
	{
		if (!$condition instanceof Condition)
		{
			throw new InvalidFilterException($condition);
		}

		$field = $condition->getLeftOperand();
		$value = $condition->getRightOperand();
		$legacyField = is_string($field) ? (self::FILTER_FIELDS[$field] ?? null) : null;
		if ($legacyField === null || $this->containsExpression($value))
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		$left = (int)($item[$legacyField] ?? 0);
		$operator = $condition->getOperator();

		return match ($operator)
		{
			Operator::Equal => $this->matchesScalar($left, $value, $condition, static fn(int $left, int $right): bool => $left === $right),
			Operator::NotEqual => $this->matchesScalar($left, $value, $condition, static fn(int $left, int $right): bool => $left !== $right),
			Operator::Greater => $this->matchesScalar($left, $value, $condition, static fn(int $left, int $right): bool => $left > $right),
			Operator::GreaterOrEqual => $this->matchesScalar($left, $value, $condition, static fn(int $left, int $right): bool => $left >= $right),
			Operator::Less => $this->matchesScalar($left, $value, $condition, static fn(int $left, int $right): bool => $left < $right),
			Operator::LessOrEqual => $this->matchesScalar($left, $value, $condition, static fn(int $left, int $right): bool => $left <= $right),
			Operator::In => $this->matchesList($left, $value, $condition),
			Operator::Between => $this->matchesBetween($left, $value, $condition),
		};
	}

	private function matchesScalar(
		int $left,
		mixed $value,
		Condition $condition,
		callable $comparison,
	): bool
	{
		if (!is_int($value))
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		return $comparison($left, $value);
	}

	private function matchesList(int $left, mixed $value, Condition $condition): bool
	{
		if (!is_array($value) || !array_is_list($value) || !$this->isIntegerList($value))
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		return in_array($left, $value, true);
	}

	private function matchesBetween(int $left, mixed $value, Condition $condition): bool
	{
		if (
			!is_array($value)
			|| !array_is_list($value)
			|| count($value) !== 2
			|| !$this->isIntegerList($value)
		)
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		return $left >= $value[0] && $left <= $value[1];
	}

	/** @param array<mixed> $value */
	private function isIntegerList(array $value): bool
	{
		foreach ($value as $item)
		{
			if (!is_int($item))
			{
				return false;
			}
		}

		return true;
	}

	private function containsExpression(mixed $value): bool
	{
		if ($value instanceof Expression)
		{
			return true;
		}
		if (!is_array($value))
		{
			return false;
		}

		foreach ($value as $item)
		{
			if ($this->containsExpression($item))
			{
				return true;
			}
		}

		return false;
	}

	private function assertXmlIdIsAvailable(int $fieldId, string $xmlId, ?int $excludedId = null): void
	{
		foreach ($this->getEnumValues($fieldId, null, ['XML_ID' => $xmlId]) as $item)
		{
			if ($excludedId === null || (int)$item['ID'] !== $excludedId)
			{
				throw new CustomFieldEnumException(
					\GetMessage('USER_TYPE_XML_ID_UNIQ', ['#XML_ID#' => $xmlId]),
				);
			}
		}
	}

	private function setXmlId(int $id, string $xmlId): void
	{
		global $DB, $CACHE_MANAGER;

		$update = $DB->PrepareUpdate('b_user_field_enum', ['XML_ID' => $xmlId]);
		if ($update === '' || !$DB->Query('UPDATE b_user_field_enum SET ' . $update . ' WHERE ID = ' . $id))
		{
			throw new CustomFieldEnumException('Cannot change custom enum value.');
		}

		if (defined('CACHED_b_user_field_enum') && CACHED_b_user_field_enum !== false)
		{
			$CACHE_MANAGER->CleanDir('b_user_field_enum');
		}
	}

	private function setValues(int $fieldId, array $values): void
	{
		if ($this->enum->SetEnumValues($fieldId, $values))
		{
			return;
		}

		global $APPLICATION;
		$exception = $APPLICATION->GetException();
		$message = trim(strip_tags((string)$exception));

		throw new CustomFieldEnumException($message !== '' ? $message : 'Cannot change custom enum value.');
	}
}
