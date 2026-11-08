<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item;

use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\Binding\EntityContactTable;
use Bitrix\Crm\Binding\LeadContactTable;
use Bitrix\Crm\Binding\QuoteContactTable;
use Bitrix\Crm\FieldMultiTable;
use Bitrix\Crm\Integrity\DuplicateCommunicationMatchCodeTable;
use Bitrix\Crm\Model\LastCommunicationTable;
use Bitrix\Crm\Observer\Entity\ObserverTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Tracking\Internals\TraceEntityTable;
use Bitrix\Crm\UtmTable;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Provider\Item\RestItemProvider;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\Service\ParentFieldManager;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\Condition as OrmCondition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Structure\Structure;
use Bitrix\Rest\V3\Structure\Filtering\Condition as RestCondition;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

final class ItemFilterMapper
{
	private const MULTIFIELD_TYPES = [
		'phone' => 'PHONE',
		'email' => 'EMAIL',
		'web' => 'WEB',
		'im' => 'IM',
	];

	private const MULTIFIELD_FIELDS = [
		'value' => 'VALUE',
		'valueTypeId' => 'VALUE_TYPE',
	];

	private const UTM_CODES = [
		'source' => UtmTable::ENUM_CODE_UTM_SOURCE,
		'medium' => UtmTable::ENUM_CODE_UTM_MEDIUM,
		'campaign' => UtmTable::ENUM_CODE_UTM_CAMPAIGN,
		'content' => UtmTable::ENUM_CODE_UTM_CONTENT,
		'term' => UtmTable::ENUM_CODE_UTM_TERM,
	];

	private const LAST_COMMUNICATION_TYPES = [
		'communicationTime' => LastCommunicationTable::ENUM_LAST_TIME,
		'callTime' => LastCommunicationTable::ENUM_CALL_TIME,
		'emailTime' => LastCommunicationTable::ENUM_EMAIL_TIME,
		'imolTime' => LastCommunicationTable::ENUM_IM_OPEN_LINES_TIME,
		'webformTime' => LastCommunicationTable::ENUM_WEB_FORM_TIME,
	];

	private const LATER_BINDING_ALIAS = 'later_trace_binding';

	public function __construct(
		private readonly EntityTypeSettings $entityTypeSettings,
		private readonly ItemDtoMapper $itemDtoMapper,
		private readonly CustomFieldValueConverter $customFieldValueConverter = new CustomFieldValueConverter(),
		private readonly ?string $itemDataClass = null,
		private readonly int $userId = 0,
	)
	{
	}

	public function map(ItemListFilterStructure $filter, Dto $dto): ConditionTree
	{
		return $this->mapGroup($filter, $dto);
	}

	private function mapGroup(ItemListFilterStructure $filter, Dto $dto, bool $isInsideOr = false): ConditionTree
	{
		$tree = (new ConditionTree())
			->logic($filter->getLogic()->value)
			->negative($filter->isNegative())
		;
		$isInsideOr = $isInsideOr || $filter->getLogic()->value === ConditionTree::LOGIC_OR;
		$multifieldConditions = [];
		$crmRelationConditions = [];

		$conditions = $filter->getConditions();
		if ($filter->getLogic()->value === ConditionTree::LOGIC_AND && !$filter->isNegative())
		{
			$conditions = $this->flattenAndConditions($filter);
		}

		foreach ($conditions as $condition)
		{
			if ($condition instanceof ItemListFilterStructure)
			{
				$tree->addCondition($this->mapGroup($condition, $dto, $isInsideOr));

				continue;
			}

			if ($isInsideOr && $this->isTrackingSourceCondition($condition))
			{
				$this->throwUnsupportedTrackingSourceFilter();
			}

			if (
				$filter->getLogic()->value === ConditionTree::LOGIC_AND
				&& $this->isMultifieldCondition($condition)
			)
			{
				[$fieldName] = explode('.', (string)$condition->getLeftOperand(), 2);
				$multifieldConditions[$fieldName][] = $condition;

				continue;
			}
			if (
				$filter->getLogic()->value === ConditionTree::LOGIC_AND
				&& $this->isCrmRelationCondition((string)$condition->getLeftOperand(), $dto)
			)
			{
				[$relationName] = explode('.', (string)$condition->getLeftOperand(), 2);
				$crmRelationConditions[$relationName][] = $condition;

				continue;
			}

			$tree->addCondition($this->mapCondition($condition, $dto));
		}

		foreach ($multifieldConditions as $fieldName => $conditions)
		{
			$tree->addCondition($this->mapMultifieldConditions($fieldName, $conditions));
		}

		foreach ($crmRelationConditions as $relationName => $conditions)
		{
			$positiveConditions = [];
			foreach ($conditions as $condition)
			{
				if ($condition->getOperator() === Operator::NotEqual && $condition->getRightOperand() !== null)
				{
					$tree->addCondition($this->mapCondition($condition, $dto));

					continue;
				}

				$positiveConditions[] = $condition;
			}

			if ($positiveConditions !== [])
			{
				$tree->addCondition($this->mapCrmRelationConditions($relationName, $positiveConditions, $dto));
			}
		}

		return $tree;
	}

	/**
	 * Compact filters represent every condition as a positive AND group. Flattening
	 * such groups lets multifield conditions share one storage-row subquery.
	 *
	 * @return array<int, RestCondition|ItemListFilterStructure>
	 */
	private function flattenAndConditions(ItemListFilterStructure $filter): array
	{
		$conditions = [];
		foreach ($filter->getConditions() as $condition)
		{
			if (
				$condition instanceof ItemListFilterStructure
				&& $condition->getLogic()->value === ConditionTree::LOGIC_AND
				&& !$condition->isNegative()
				&& $condition->isCompactGroup()
			)
			{
				$conditions = array_merge($conditions, $this->flattenAndConditions($condition));

				continue;
			}

			$conditions[] = $condition;
		}

		return $conditions;
	}

	private function mapCondition(RestCondition $condition, Dto $dto): OrmCondition|ConditionTree
	{
		$fieldName = (string)$condition->getLeftOperand();
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		if ($operator === Operator::In && !is_array($value))
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}
		if (
			$operator === Operator::In
			&& $value === []
			&& !$this->isMultifieldCondition($condition)
		)
		{
			return new OrmCondition('id', Operator::Equal->value, 0);
		}
		return match (true)
		{
			$fieldName === Item::trackingSourceId => $this->mapTrackingSourceCondition($operator, $value),
			$this->isCrmRelationIdCondition($fieldName, $dto) => $this->mapCrmRelationIdCondition(
				$fieldName,
				$operator,
				$value,
				$dto,
			),
			$this->isCrmRelationCondition($fieldName, $dto) => $this->mapCrmRelationCondition(
				$fieldName,
				$operator,
				$value,
				$dto,
			),
			$this->isMultifieldCondition($condition) => $this->mapMultifieldConditions(
				explode('.', $fieldName, 2)[0],
				[$condition],
			),
			$fieldName === 'contactId' => $this->mapContactCondition($fieldName, $operator, $value, true),
			$fieldName === 'contactsId' => $this->mapContactCondition($fieldName, $operator, $value, false),
			$fieldName === 'observersId' => $this->mapObserverCondition($fieldName, $operator, $value),
			str_starts_with($fieldName, 'utm.') => $this->mapUtmCondition($fieldName, $operator, $value),
			str_starts_with($fieldName, 'lastCommunication.') =>
				$this->mapLastCommunicationCondition($fieldName, $operator, $value),
			str_starts_with($fieldName, 'UF_') =>
				$this->mapCustomFieldCondition($fieldName, $operator, $value, $dto),
			default => $this->mapScalarCondition($fieldName, $operator, $value),
		};
	}

	private function isCrmRelationIdCondition(string $fieldName, Dto $dto): bool
	{
		if (!str_contains($fieldName, '.') && !str_starts_with($fieldName, 'related'))
		{
			return false;
		}

		foreach ($dto->getFields() as $field)
		{
			$relation = $field->getRelation();
			if ($relation === null || $relation->thisField !== $fieldName)
			{
				continue;
			}

			$targetDtoClass = $field->getElementType() ?? $field->getPropertyType();
			if (!is_string($targetDtoClass))
			{
				continue;
			}

			if ((new ItemDtoGenerator())->isGeneratedDtoClass($targetDtoClass))
			{
				return true;
			}
		}

		return false;
	}

	private function mapCrmRelationIdCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
		Dto $dto,
	): OrmCondition|ConditionTree
	{
		foreach ($dto->getFields() as $field)
		{
			$relation = $field->getRelation();
			if ($relation?->thisField === $fieldName)
			{
				if ($value === null && $field->getPropertyName() === 'contact')
				{
					$query = $this->createContactBindingQuery();
					$query->where('IS_PRIMARY', true);

					return $this->createIdSubqueryCondition($query, $operator === Operator::Equal);
				}

				if ($value === null && !$relation->multiple)
				{
					$itemFieldName = $this->itemDtoMapper->mapDtoFieldNameToItem($fieldName);
					if ($itemFieldName !== null)
					{
						return new OrmCondition($itemFieldName, $operator->value, null);
					}
				}

				return $this->mapCrmRelationCondition(
					$field->getPropertyName() . '.id',
					$operator,
					$value,
					$dto,
				);
			}
		}

		$this->throwUnsupportedFilter($fieldName, $operator, $value);
	}

	/**
	 * Maps conditions that already belong to one related item. Multifield conditions are
	 * deliberately grouped before the target query is built, so their storage predicates
	 * are evaluated against one row of the related item.
	 *
	 * @param RestCondition[] $conditions
	 */
	private function mapConditionGroup(array $conditions, Dto $dto): ConditionTree
	{
		$tree = (new ConditionTree())->logic(ConditionTree::LOGIC_AND);
		$multifieldConditions = [];
		foreach ($conditions as $condition)
		{
			if ($this->isMultifieldCondition($condition))
			{
				[$fieldName] = explode('.', (string)$condition->getLeftOperand(), 2);
				$multifieldConditions[$fieldName][] = $condition;

				continue;
			}

			$tree->addCondition($this->mapCondition($condition, $dto));
		}

		foreach ($multifieldConditions as $fieldName => $fieldConditions)
		{
			$tree->addCondition($this->mapMultifieldConditions($fieldName, $fieldConditions));
		}

		return $tree;
	}

	private function isCrmRelationCondition(string $fieldName, Dto $dto): bool
	{
		$parts = explode('.', $fieldName);
		$relation = $dto->getFields()[$parts[0]] ?? null;
		if ($relation === null)
		{
			return false;
		}

		$targetDtoClass = $relation->getElementType() ?? $relation->getPropertyType();
		if (!is_string($targetDtoClass))
		{
			return false;
		}

		return (new ItemDtoGenerator())->isGeneratedDtoClass($targetDtoClass);
	}

	private function mapCrmRelationCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
		Dto $dto,
	): OrmCondition|ConditionTree
	{
		$negative = $operator === Operator::NotEqual && $value !== null;
		$mapped = $this->mapCrmRelationConditions(
			explode('.', $fieldName, 2)[0],
			[new RestCondition($fieldName, $negative ? Operator::Equal : $operator, $value)],
			$dto,
		);
		if (!$negative)
		{
			return $mapped;
		}

		return (new ConditionTree())->addCondition($mapped)->negative();
	}

	/**
	 * Maps conditions for one relation into one target-item subquery. Keeping all positive
	 * conditions in this query is what correlates two fields of one multifield row.
	 *
	 * @param RestCondition[] $conditions
	 */
	private function mapCrmRelationConditions(
		string $relationName,
		array $conditions,
		Dto $dto,
	): OrmCondition|ConditionTree
	{
		$condition = $conditions[0];
		$relationField = $dto->getFields()[$relationName] ?? null;
		if ($relationField === null || $relationField->getRelation() === null)
		{
			$this->throwUnsupportedFilter(
				(string)$condition->getLeftOperand(),
				$condition->getOperator(),
				$condition->getRightOperand(),
			);
		}
		$relation = $relationField->getRelation();
		if (
			count($conditions) === 1
			&& $condition->getRightOperand() === null
			&& (string)$condition->getLeftOperand() === $relationName . '.id'
			&& !$relation->multiple
		)
		{
			if ($relationName === 'contact')
			{
				$query = $this->createContactBindingQuery();
				$query->where('IS_PRIMARY', true);

				return $this->createIdSubqueryCondition(
					$query,
					$condition->getOperator() === Operator::Equal,
				);
			}

			$itemFieldName = $this->resolveRelationItemField($relation->thisField);
			if ($itemFieldName !== null)
			{
				return new OrmCondition($itemFieldName, $condition->getOperator()->value, null);
			}
		}

		$targetDtoClass = $relationField->getElementType() ?? $relationField->getPropertyType();
		$targetEntityType = $this->getEntityTypeByGeneratedDtoClass($targetDtoClass);
		if ($targetEntityType === null)
		{
			$this->throwUnsupportedFilter(
				(string)$condition->getLeftOperand(),
				$condition->getOperator(),
				$condition->getRightOperand(),
			);
		}

		$targetDto = Structure::getDto($targetDtoClass) ?? $targetDtoClass::create();
		Structure::addDto($targetDto);
		$targetFactory = Container::getInstance()->getFactory($targetEntityType->getId());
		$targetDataClass = $targetFactory?->getDataClass();
		if ($targetDataClass === null)
		{
			$this->throwUnsupportedFilter(
				(string)$condition->getLeftOperand(),
				$condition->getOperator(),
				$condition->getRightOperand(),
			);
		}

		$targetSettings = EntityTypeSettings::of($targetEntityType);
		$targetMapper = new self(
			$targetSettings,
			new ItemDtoMapper($targetSettings),
			itemDataClass: $targetDataClass,
			userId: $this->userId,
		);
		$targetConditions = [];
		foreach ($conditions as $condition)
		{
			[, $targetFieldName] = explode('.', (string)$condition->getLeftOperand(), 2);
			$targetConditions[] = new RestCondition(
				$targetFieldName ?? 'id',
				$condition->getOperator(),
				$condition->getRightOperand(),
			);
		}
		$targetCondition = $targetMapper->mapConditionGroup($targetConditions, $targetDto);
		$targetProvider = new RestItemProvider($targetEntityType, $this->userId);
		$targetQuery = null;
		$usesMyCompanyBaseFieldsQuery = false;
		if ($relationName === 'myCompany')
		{
			if (!Container::getInstance()->getUserPermissions($this->userId)->myCompany()->canReadBaseFields())
			{
				$targetCondition->addCondition(new OrmCondition('ID', '=', 0));
			}
			elseif ($this->containsOnlyBaseMyCompanyFields($targetConditions))
			{
				$usesMyCompanyBaseFieldsQuery = true;
				$targetQuery = $targetProvider->createMyCompanyBaseFieldsIdQuery(new ItemFilter($targetCondition));
			}
			else
			{
				$targetCondition->addCondition(new OrmCondition('isMyCompany', '=', true));
			}
		}
		if (!$usesMyCompanyBaseFieldsQuery)
		{
			$targetQuery = $targetProvider->createIdQuery(
				new ItemFilter($targetCondition),
				applyListRestrictions: $relationName !== 'myCompany',
			);
		}
		if ($targetQuery === null)
		{
			$this->throwUnsupportedFilter(
				(string)$condition->getLeftOperand(),
				$condition->getOperator(),
				$condition->getRightOperand(),
			);
		}

		if ($relation->multiple)
		{
			return $this->mapMultipleCrmRelationCondition(
				$relationName,
				$targetQuery,
				$conditions,
			);
		}

		if ($relationName === 'contact')
		{
			$query = $this->createContactBindingQuery();
			$query->where('IS_PRIMARY', true);
			$query->where(new OrmCondition('CONTACT_ID', 'in', $targetQuery));

			return $this->createIdSubqueryCondition($query, false);
		}

		$itemFieldName = $this->resolveRelationItemField($relation->thisField);
		if ($itemFieldName === null)
		{
			$this->throwUnsupportedFilter(
				(string)$condition->getLeftOperand(),
				$condition->getOperator(),
				$condition->getRightOperand(),
			);
		}

		return $this->createColumnSubqueryCondition($itemFieldName, $targetQuery, false);
	}

	private function resolveRelationItemField(string $sourceField): ?string
	{
		$itemFieldName = $this->itemDtoMapper->mapDtoFieldNameToItem($sourceField);
		if ($itemFieldName !== null || !str_starts_with($sourceField, 'related'))
		{
			return $itemFieldName;
		}

		$parentType = EntityType::fromCode(substr($sourceField, strlen('related'), -strlen('Id')));

		return $parentType === null
			? null
			: ParentFieldManager::getParentFieldName($parentType->getId());
	}

	private function mapMultipleCrmRelationCondition(
		string $relationName,
		Query $targetQuery,
		array $conditions,
	): OrmCondition|ConditionTree
	{
		$condition = $conditions[0];
		$fieldName = (string)$condition->getLeftOperand();
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		if (count($conditions) === 1 && $value === null && $fieldName === $relationName . '.id')
		{
			$negative = $operator === Operator::Equal;
			$query = match ($relationName)
			{
				'companies' => ContactCompanyTable::query()->setSelect(['CONTACT_ID']),
				'contacts' => $this->createContactBindingQuery(),
				default => null,
			};
			if ($query !== null)
			{
				return $this->createIdSubqueryCondition($query, $negative);
			}
		}
		if ($relationName === 'companies')
		{
			if (!$this->entityTypeSettings->hasCompanyBindings())
			{
				$this->throwUnsupportedFilter($fieldName, $operator, $value);
			}

			$query = ContactCompanyTable::query()
				->setSelect(['CONTACT_ID'])
				->where('COMPANY_ID', 'in', $targetQuery)
			;

			return $this->createIdSubqueryCondition($query, false);
		}

		if ($relationName === 'contacts')
		{
			if (!$this->entityTypeSettings->hasContactBindings())
			{
				$this->throwUnsupportedFilter($fieldName, $operator, $value);
			}

			$query = $this->createContactBindingQuery();
			$query->where(new OrmCondition('CONTACT_ID', 'in', $targetQuery));

			return $this->createIdSubqueryCondition($query, false);
		}

		$this->throwUnsupportedFilter($fieldName, $operator, $value);
	}

	/** @param RestCondition[] $conditions */
	private function containsOnlyBaseMyCompanyFields(array $conditions): bool
	{
		foreach ($conditions as $condition)
		{
			$fieldName = (string)$condition->getLeftOperand();
			if (!in_array($fieldName, ['id', 'title'], true))
			{
				return false;
			}
		}

		return true;
	}

	private function createColumnSubqueryCondition(
		string $column,
		Query $query,
		bool $negative,
	): OrmCondition|ConditionTree
	{
		$condition = new OrmCondition($column, 'in', $query);
		if (!$negative)
		{
			return $condition;
		}

		return (new ConditionTree())->addCondition($condition)->negative();
	}

	private function getEntityTypeByGeneratedDtoClass(string $dtoClass): ?\Bitrix\Crm\V2\Public\EntityType
	{
		return (new ItemDtoGenerator())->getEntityTypeByGeneratedDtoClass($dtoClass);
	}

	private function isMultifieldCondition(RestCondition $condition): bool
	{
		$parts = explode('.', (string)$condition->getLeftOperand(), 2);

		return count($parts) === 2
			&& isset(self::MULTIFIELD_TYPES[$parts[0]], self::MULTIFIELD_FIELDS[$parts[1]]);
	}

	/**
	 * @param RestCondition[] $conditions
	 */
	private function mapMultifieldConditions(string $fieldName, array $conditions): OrmCondition
	{
		if (!$this->entityTypeSettings->hasMultifields())
		{
			$this->throwUnsupportedFilter($fieldName, Operator::Equal, null);
		}

		foreach ($conditions as $condition)
		{
			if ($condition->getOperator() === Operator::In && $condition->getRightOperand() === [])
			{
				return new OrmCondition('id', Operator::Equal->value, 0);
			}
		}

		if (count($conditions) === 1 && $fieldName === 'phone')
		{
			$condition = $conditions[0];
			$fieldParts = explode('.', (string)$condition->getLeftOperand(), 2);
			if ($fieldParts[1] === 'value')
			{
				return new OrmCondition(
					'id',
					'in',
					$this->createPhoneIndexQuery(
						$condition->getOperator(),
						$condition->getRightOperand(),
						(string)$condition->getLeftOperand(),
					),
				);
			}
		}

		$query = FieldMultiTable::query()
			->setSelect(['ELEMENT_ID'])
			->where(
				'ENTITY_ID',
				\CCrmOwnerType::ResolveName($this->entityTypeSettings->getEntityType()->getId()),
			)
			->where('TYPE_ID', self::MULTIFIELD_TYPES[$fieldName])
		;

		foreach ($conditions as $condition)
		{
			$fieldParts = explode('.', (string)$condition->getLeftOperand(), 2);
			$operator = $condition->getOperator();
			$value = $condition->getRightOperand();
			$storageField = self::MULTIFIELD_FIELDS[$fieldParts[1]];

			if ($fieldName === 'phone' && $fieldParts[1] === 'value')
			{
				$this->addPhoneValueCondition($query, $operator, $value, (string)$condition->getLeftOperand());

				continue;
			}

			if ($operator === Operator::Equal)
			{
				$query->where($storageField, '=', $value);

				continue;
			}

			if ($operator === Operator::In && is_array($value) && $value !== [])
			{
				$query->whereIn($storageField, $value);

				continue;
			}

			$this->throwUnsupportedFilter((string)$condition->getLeftOperand(), $operator, $value);
		}

		return new OrmCondition('id', 'in', $query);
	}

	private function addPhoneValueCondition(
		Query $query,
		Operator $operator,
		mixed $value,
		string $fieldName,
	): void
	{
		$query->where(
			'ELEMENT_ID',
			'in',
			$this->createPhoneIndexQuery($operator, $value, $fieldName),
		);
	}

	private function createPhoneIndexQuery(Operator $operator, mixed $value, string $fieldName): Query
	{
		if (!in_array($operator, [Operator::Equal, Operator::In], true))
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}

		$normalizedValues = [];
		foreach (is_array($value) ? $value : [$value] as $phone)
		{
			$normalizedPhone = \Bitrix\Crm\Integrity\DuplicateCommunicationCriterion::normalizePhone($phone);
			if ($normalizedPhone !== '')
			{
				$normalizedValues[] = $normalizedPhone;
			}
		}
		$normalizedValues = array_values(array_unique($normalizedValues));
		if ($normalizedValues === [])
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}

		return DuplicateCommunicationMatchCodeTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $this->entityTypeSettings->getEntityType()->getId())
			->where('TYPE', 'PHONE')
			->whereIn('VALUE', $normalizedValues)
		;
	}

	private function mapScalarCondition(string $fieldName, Operator $operator, mixed $value): OrmCondition
	{
		if ($operator === Operator::In && $value === [])
		{
			return new OrmCondition('id', Operator::Equal->value, 0);
		}

		if (
			in_array($fieldName, ['beginTime', 'closeTime'], true)
			&& !$this->entityTypeSettings->hasBeginCloseDates()
		)
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}

		$itemFieldName = $this->itemDtoMapper->mapDtoFieldNameToItem($fieldName);
		if ($itemFieldName === null)
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}

		return new OrmCondition($itemFieldName, $operator->value, $value);
	}

	private function mapContactCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
		bool $isPrimary,
	): OrmCondition|ConditionTree
	{
		if (!$this->entityTypeSettings->hasContactBindings())
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}
		$this->requireBindingOperator($fieldName, $operator, $value);

		$query = $this->createContactBindingQuery();
		if ($value !== null)
		{
			$this->applyBindingValueFilter($query, 'CONTACT_ID', $operator, $value);
		}
		if ($isPrimary)
		{
			$query->where('IS_PRIMARY', true);
		}

		return $this->createIdSubqueryCondition(
			$query,
			$this->shouldNegateBindingSubquery($operator, $value),
		);
	}

	private function createContactBindingQuery(): Query
	{
		$entityTypeId = $this->entityTypeSettings->getEntityType()->getId();
		[$tableClass, $ownerField] = match ($entityTypeId)
		{
			OwnerType::DEAL => [DealContactTable::class, 'DEAL_ID'],
			OwnerType::LEAD => [LeadContactTable::class, 'LEAD_ID'],
			OwnerType::QUOTE => [QuoteContactTable::class, 'QUOTE_ID'],
			OwnerType::COMPANY => [ContactCompanyTable::class, 'COMPANY_ID'],
			default => [EntityContactTable::class, 'ENTITY_ID'],
		};

		/** @var class-string<DataManager> $tableClass */
		$query = $tableClass::query()->setSelect([$ownerField]);
		if ($tableClass === EntityContactTable::class)
		{
			$query->where('ENTITY_TYPE_ID', $entityTypeId);
		}

		return $query;
	}

	private function mapObserverCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
	): OrmCondition|ConditionTree
	{
		if (!$this->entityTypeSettings->hasObservers())
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}
		$this->requireBindingOperator($fieldName, $operator, $value);

		$query = ObserverTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $this->entityTypeSettings->getEntityType()->getId())
		;
		if ($value !== null)
		{
			$this->applyBindingValueFilter($query, 'USER_ID', $operator, $value);
		}

		return $this->createIdSubqueryCondition(
			$query,
			$this->shouldNegateBindingSubquery($operator, $value),
		);
	}

	private function mapUtmCondition(string $fieldName, Operator $operator, mixed $value): OrmCondition|ConditionTree
	{
		if (!$this->entityTypeSettings->hasCrmTracking())
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}

		$childName = substr($fieldName, strlen('utm.'));
		$query = UtmTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $this->entityTypeSettings->getEntityType()->getId())
			->where('CODE', self::UTM_CODES[$childName])
		;

		if ($value === null)
		{
			$query->where('VALUE', '!=', '');

			return $this->createIdSubqueryCondition($query, $operator === Operator::Equal);
		}

		$query->where('VALUE', $operator === Operator::NotEqual ? '=' : $operator->value, $value);

		return $this->createIdSubqueryCondition($query, $operator === Operator::NotEqual);
	}

	private function isTrackingSourceCondition(RestCondition $condition): bool
	{
		$fieldName = (string)$condition->getLeftOperand();

		return $fieldName === Item::trackingSourceId || str_ends_with($fieldName, '.' . Item::trackingSourceId);
	}

	/**
	 * The source of an item is the source of the trace of its last binding (the greatest binding ID), so
	 * the filter takes the bindings of the wanted sources that no later binding of the same item follows.
	 */
	private function mapTrackingSourceCondition(Operator $operator, mixed $value): OrmCondition|ConditionTree
	{
		if (!$this->entityTypeSettings->hasCrmTracking())
		{
			$this->throwUnsupportedFilter(Item::trackingSourceId, $operator, $value);
		}
		if (!in_array($operator, [Operator::Equal, Operator::In], true) || $value === null)
		{
			$this->throwUnsupportedTrackingSourceFilter();
		}

		$query = $this->createLastTraceBindingQuery();
		$sourceIds = array_map('intval', is_array($value) ? $value : [$value]);
		$query->whereIn('TRACE.SOURCE_ID', $sourceIds);

		return $this->createIdSubqueryCondition($query, false);
	}

	private function createLastTraceBindingQuery(): Query
	{
		$entityTypeId = $this->entityTypeSettings->getEntityType()->getId();
		$query = TraceEntityTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $entityTypeId)
		;
		$bindingAlias = $query->getInitAlias();
		$laterBindingQuery = TraceEntityTable::query()
			->setCustomBaseTableAlias(self::LATER_BINDING_ALIAS)
			->setSelect(['ID'])
			->where('ENTITY_TYPE_ID', $entityTypeId)
			->where('ENTITY_ID', new SqlExpression('?#.?#', $bindingAlias, 'ENTITY_ID'))
			->where('ID', '>', new SqlExpression('?#.?#', $bindingAlias, 'ID'))
		;

		return $query->whereNotExists($laterBindingQuery);
	}

	private function throwUnsupportedTrackingSourceFilter(): never
	{
		throw new RequestValidationException([
			new Error(
				new LocalizableMessage('CRM_V2_REST_ITEM_FILTER_TRACKING_SOURCE_UNSUPPORTED'),
				Item::trackingSourceId,
			),
		]);
	}

	private function mapLastCommunicationCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
	): OrmCondition|ConditionTree
	{
		$childName = substr($fieldName, strlen('lastCommunication.'));
		$query = LastCommunicationTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $this->entityTypeSettings->getEntityType()->getId())
			->where('TYPE', self::LAST_COMMUNICATION_TYPES[$childName])
		;

		if ($value === null)
		{
			$query->whereNotNull('LAST_COMMUNICATION_TIME');

			return $this->createIdSubqueryCondition($query, $operator === Operator::Equal);
		}

		$query->where(
			'LAST_COMMUNICATION_TIME',
			$operator === Operator::NotEqual ? '=' : $operator->value,
			$value,
		);

		return $this->createIdSubqueryCondition($query, $operator === Operator::NotEqual);
	}

	private function mapCustomFieldCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
		Dto $dto,
	): OrmCondition|ConditionTree
	{
		/** @var DtoField $field */
		$field = $dto->getFields()[$fieldName];
		if ($operator === Operator::In && is_array($value))
		{
			$value = array_map(
				fn(mixed $item): mixed => $this->customFieldValueConverter->convertElement($field, $item),
				$value,
			);
		}
		else
		{
			$value = $this->customFieldValueConverter->convert($field, $value);
		}

		if ($this->isAddressField($field))
		{
			return $this->mapAddressCondition($field, $fieldName, $operator, $value);
		}

		if (
			$operator === Operator::NotEqual
			&& ($field->isMultiple() || $field->getElementType() !== null)
		)
		{
			return (new ConditionTree())
				->addCondition(new OrmCondition($fieldName, Operator::Equal->value, $value))
				->negative()
			;
		}

		return new OrmCondition($fieldName, $operator->value, $value);
	}

	private function isAddressField(DtoField $field): bool
	{
		$propertyType = $field->getElementType() ?? $field->getPropertyType();

		return is_a($propertyType, AddressDto::class, true);
	}

	private function mapAddressCondition(
		DtoField $field,
		string $fieldName,
		Operator $operator,
		mixed $value,
	): OrmCondition|ConditionTree
	{
		$values = $operator === Operator::In ? $value : [$value];
		$isMultiple = $field->isMultiple() || $field->getElementType() !== null;
		$match = $this->createAddressMatch(
			$isMultiple ? $fieldName . '_SINGLE' : $fieldName,
			$values,
		);
		if ($isMultiple)
		{
			$dataClass = $this->itemDataClass;
			if ($dataClass === null)
			{
				$factory = Container::getInstance()->getFactory(
					$this->entityTypeSettings->getEntityType()->getId(),
				);
				if ($factory === null)
				{
					$this->throwUnsupportedFilter($fieldName, $operator, $value);
				}

				$dataClass = $factory->getDataClass();
			}

			$query = $dataClass::query()
				->setSelect(['ID'])
				->where($match)
			;

			return $this->createIdSubqueryCondition($query, $operator === Operator::NotEqual);
		}

		if ($operator === Operator::NotEqual)
		{
			$match->negative();
		}

		return $match;
	}

	private function createAddressMatch(string $fieldName, array $values): ConditionTree
	{
		$match = (new ConditionTree())->logic(ConditionTree::LOGIC_OR);
		$sqlHelper = Application::getConnection()->getSqlHelper();
		foreach ($values as $visibleValue)
		{
			$visibleValue = (string)$visibleValue;
			$valueWithSeparator = $visibleValue . '|';
			$prefixLength = mb_strlen($valueWithSeparator);
			$prefix = new ExpressionField(
				'REST_' . $fieldName . '_PREFIX_' . $prefixLength,
				$sqlHelper->getSubstrFunction('%s', 1, $prefixLength),
				$fieldName,
			);

			$match
				->addCondition(new OrmCondition($fieldName, Operator::Equal->value, $visibleValue))
				->addCondition(new OrmCondition($prefix, Operator::Equal->value, $valueWithSeparator))
			;
		}

		return $match;
	}

	private function requireBindingOperator(string $fieldName, Operator $operator, mixed $value): void
	{
		if (!in_array($operator, [Operator::Equal, Operator::NotEqual, Operator::In], true))
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}
	}

	private function applyBindingValueFilter(Query $query, string $fieldName, Operator $operator, mixed $value): void
	{
		if ($operator === Operator::In)
		{
			$query->whereIn($fieldName, $value);

			return;
		}

		$query->where($fieldName, '=', $value);
	}

	private function createIdSubqueryCondition(Query $query, bool $negative): OrmCondition|ConditionTree
	{
		$condition = new OrmCondition('id', 'in', $query);
		if (!$negative)
		{
			return $condition;
		}

		return (new ConditionTree())->addCondition($condition)->negative();
	}

	private function shouldNegateBindingSubquery(Operator $operator, mixed $value): bool
	{
		return $value === null
			? $operator === Operator::Equal
			: $operator === Operator::NotEqual
		;
	}

	private function throwUnsupportedFilter(string $fieldName, Operator $operator, mixed $value): never
	{
		throw new InvalidFilterException([$fieldName, $operator->value, $value]);
	}
}
