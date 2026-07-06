<?php

namespace Bitrix\Rest\V3\Data;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Order;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\ClassRequireAttributeException;
use Bitrix\Rest\V3\Exception\Internal\OrmSaveException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Exception\TooManyAttributesException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationResultStructure;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationSelectStructure;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationType;
use Bitrix\Rest\V3\Structure\Aggregation\ResultItem;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\ColumnExpression;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\LengthExpression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderItem;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Exception;
use ReflectionClass;
use ReflectionException;

class OrmRepository extends Repository
{
	protected string $dataClass;

	/**
	 * @param string $dtoClass
	 * @throws ReflectionException
	 */
	public function __construct(protected string $dtoClass)
	{
		$attributes = (new ReflectionClass($this->dtoClass))
			->getAttributes(OrmEntity::class)
		;

		$attributesCount = count($attributes);

		if ($attributesCount > 1)
		{
			throw new TooManyAttributesException($this->dtoClass, OrmEntity::class, 1);
		}
		if ($attributesCount < 1)
		{
			throw new ClassRequireAttributeException($this->dtoClass, OrmEntity::class);
		}

		$this->dataClass = $attributes[0]->newInstance()->entity;
	}

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getAllWithAggregate(AggregationSelectStructure $select, ?FilterStructure $filter = null): AggregationResultStructure
	{
		$queryMap = [];
		/** @var DataManager $dataClass */
		$dataClass = $this->dataClass;
		$query = $dataClass::query();
		foreach ($select as $function)
		{
			$queryMap[$function->aggregation->value][$function->field] = $function->alias;
			$aggregateFunction = self::mapAggregateFunction($function->aggregation->value);
			$aggregateParam = self::mapDtoPropertyToOrmField($function->field);
			$query->addSelect(Query::expr()->{$aggregateFunction}($aggregateParam), $function->alias);
		}

		if ($filter !== null)
		{
			$ormFilter = $this->prepareFilter($filter);
			if ($ormFilter !== null)
			{
				$query->where($ormFilter);
			}
		}

		$queryResult = $query->fetch();

		$aggregationResult = new AggregationResultStructure();
		foreach ($queryMap as $aggregation => $fields)
		{
			foreach ($fields as $field => $alias)
			{
				$aggregationType = AggregationType::from($aggregation);
				$aggregateItem = new ResultItem($aggregationType, $field, $queryResult[$alias]);
				$aggregationResult->add($aggregateItem);
			}
		}

		return $aggregationResult;
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws ObjectPropertyException
	 */
	public function getAll(
		?SelectStructure $select = null,
		?FilterStructure $filter = null,
		?OrderStructure $order = null,
		?PaginationStructure $page = null,
	): DtoCollection {
		$query = $this->getQuery($select, $filter, $order, $page);

		return $this->mapCollectionToDto($query->fetchCollection());
	}

	/**
	 * @param SelectStructure|null $select
	 * @param FilterStructure|null $filter
	 * @param OrderStructure|null $order
	 * @param PaginationStructure|null $page
	 * @return Query
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function getQuery(?SelectStructure $select, ?FilterStructure $filter = null, ?OrderStructure $order = null, ?PaginationStructure $page = null): Query
	{
		/** @var DataManager $dataClass */
		$dataClass = $this->dataClass;

		/** @var Collection $collection */
		$query = $dataClass::query();

		$query->setSelect($this->prepareSelect($select));

		if ($filter !== null)
		{
			$ormFilter = $this->prepareFilter($filter);
			if ($ormFilter !== null)
			{
				$query->where($ormFilter);
			}
		}

		$query->setOrder($this->prepareOrder($order));

		if ($page !== null)
		{
			$query
				->setLimit($page->getLimit())
				->setOffset($page->getOffset())
			;
		}
		else
		{
			// hard limit
			$query->setLimit(PaginationStructure::DEFAULT_LIMIT);
		}

		return $query;
	}

	/**
	 * @throws ArgumentException
	 * @throws Exception
	 */
	final protected function mapCollectionToDto(Collection $collection): DtoCollection
	{
		$dtoCollection = new DtoCollection($this->dtoClass);

		foreach ($collection as $object)
		{
			$dtoCollection->add($this->mapObjectToDto($object, $this->dtoClass));
		}

		return $dtoCollection;
	}

	/**
	 * @throws ArgumentException
	 */
	protected function mapObjectToDto(EntityObject $object, string $dtoClass): Dto
	{
		/** @var Dto $dto */
		$dto = $dtoClass::create();
		$dtoFields = $dto->getFields();

		foreach ($object->collectValues() as $key => $value)
		{
			if (str_starts_with($key, 'UF_'))
			{
				$dto->{$key} = $value;

				continue;
			}
			if (str_starts_with($key, 'UTS_'))
			{
				continue;
			}
			$dtoProperty = self::mapOrmFieldToDtoProperty($key);
			if ($value !== null && isset($dtoFields[$dtoProperty]))
			{
				$propertyType = $dtoFields[$dtoProperty]->getPropertyType();
				if (is_subclass_of($propertyType, \BackedEnum::class))
				{
					$value = self::coerceOrmValueToEnum($propertyType, $value, $dtoProperty);
				}
			}
			$dto->{$dtoProperty} = $value;
		}

		return $dto;
	}

	protected function prepareSelect(?SelectStructure $select): array
	{
		if ($select === null)
		{
			return ['*'];
		}

		$ormFields = [];

		$dtoFields = $select->getList();

		foreach ($dtoFields as $field)
		{
			$ormFields[] = self::mapDtoPropertyToOrmField($field);
		}

		foreach ($select->getUserFields() as $field)
		{
			$ormFields[] = $field;
		}

		if (!empty($select->getRelationFields()))
		{
			$ormEntityRelationFields = [];

			foreach ($select->getRelationFields() as $field)
			{
				$ormEntityRelationFields[$field] = self::mapDtoPropertyToOrmField($field);
			}

			$ormFields = array_unique(array_merge($ormFields, $ormEntityRelationFields));
		}

		return $ormFields;
	}

	/**
	 * @param FilterStructure|null $filter
	 * @return ConditionTree|null
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	protected function prepareFilter(?FilterStructure $filter = null): ?ConditionTree
	{
		if ($filter !== null && $filter->getConditions())
		{
			$query = new ConditionTree();
			$query->logic($filter->logic()->value);
			$query->negative($filter->isNegative());

			foreach ($filter->getConditions() as $condition)
			{
				if ($condition instanceof Condition)
				{
					$query->where($this->convertFilterCondition($condition));
				}
				elseif ($condition instanceof FilterStructure)
				{
					$ormFilter = $this->prepareFilter($condition);
					if ($ormFilter !== null)
					{
						$query->where($ormFilter);
					}
				}
			}

			return $query;
		}

		return null;
	}

	protected function prepareOrder(?OrderStructure $order = null): array
	{
		$orderItems = $order !== null ? $order->getItems() : [new OrderItem('id', Order::Asc)];

		$ormOrder = [];

		foreach ($orderItems as $item)
		{
			$ormField = self::mapDtoPropertyToOrmField($item->getProperty());
			$ormOrder[$ormField] = $item->getOrder()->value;
		}

		return $ormOrder;
	}

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function convertFilterCondition(Condition $condition): \Bitrix\Main\ORM\Query\Filter\Condition
	{
		/** @var DataManager $dataClass */
		$dataClass = $this->dataClass;
		$leftOperand = $condition->getLeftOperand();
		$rightOperand = $condition->getRightOperand();

		$leftOperand = $leftOperand instanceof Expression ? $leftOperand : self::mapDtoPropertyToOrmField($leftOperand);

		$operands = [&$leftOperand, &$rightOperand];

		foreach ($operands as &$operand)
		{
			// columns
			if ($operand instanceof ColumnExpression)
			{
				$operand = new \Bitrix\Main\ORM\Query\Filter\Expressions\ColumnExpression(
					self::mapDtoPropertyToOrmField($operand->getProperty()),
				);
			}

			// length expression
			if ($operand instanceof LengthExpression)
			{
				$ormFieldName = self::mapDtoPropertyToOrmField($operand->getProperty());
				$sqlHelper = $dataClass::getEntity()->getConnection()->getSqlHelper();

				$operand = new ExpressionField(
					\Bitrix\Main\ORM\Query\Expression::getTmpName('RST'),
					$sqlHelper->getLengthFunction('%s'),
					$ormFieldName,
				);
			}

			// backed enum → scalar
			if ($operand instanceof \BackedEnum)
			{
				$operand = $operand->value;
			}
			elseif (is_array($operand))
			{
				foreach ($operand as &$item)
				{
					if ($item instanceof \BackedEnum)
					{
						$item = $item->value;
					}
				}
				unset($item);
			}
		}

		return new \Bitrix\Main\ORM\Query\Filter\Condition(
			$leftOperand,
			$condition->getOperator()->value,
			$rightOperand,
		);
	}

	/**
	 * @throws OrmSaveException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function add(Dto $dto): int
	{
		/** @var DataManager $dataClass */
		$dataClass = $this->dataClass;

		/** @var EntityObject $ormObject */
		$ormObject = $dataClass::createObject();

		foreach ($dto->toArray(rawData: true) as $propertyName => $value)
		{
			if (str_starts_with($propertyName, 'UF_'))
			{
				$ormObject->set($propertyName, $value);
			}
			else
			{
				$ormFieldName = self::mapDtoPropertyToOrmField($propertyName);
				$ormObject->set($ormFieldName, $value);
			}
		}

		$result = $ormObject->save();

		if ($result->isSuccess())
		{
			return $ormObject->getId();
		}
		else
		{
			$messages = implode(',', $result->getErrorMessages());
			$internal = new Exception($messages);

			throw new OrmSaveException($internal);
		}
	}

	public function update(int $id, Dto $dto): bool
	{
		/** @var DataManager $dataClass */
		$dataClass = $this->dataClass;

		$ormFields = $this->getOrmFieldsByDto($dto);

		return $dataClass::update($id, $ormFields)->getError() === null;
	}

	public function updateMulti(FilterStructure $filter, Dto $dto): bool
	{
		/** @var DataManager $dataClass */
		$dataClass = $this->dataClass;

		$ids = $this->getIdsByFilter($filter);
		$ormFields = $this->getOrmFieldsByDto($dto);

		return $dataClass::updateMulti($ids, $ormFields)->getError() === null;
	}

	private function getOrmFieldsByDto(Dto $dto): array
	{
		$ormFields = [];

		foreach ($dto->toArray(rawData: true) as $propertyName => $value)
		{
			if (str_starts_with($propertyName, 'UF_'))
			{
				$ormFields[$propertyName] = $value;
			}
			else
			{
				$ormFields[self::mapDtoPropertyToOrmField($propertyName)] = $value;
			}
		}

		return $ormFields;
	}

	public function delete(int $id): bool
	{
		/** @var DataManager $dataClass */
		$dataClass = $this->dataClass;
		$dataClass::delete($id);

		return true;
	}

	public function deleteMulti(FilterStructure $filter): bool
	{
		$ids = $this->getIdsByFilter($filter);
		foreach ($ids as $id)
		{
			$this->delete($id);
		}

		return true;
	}

	protected static function mapOrmFieldToDtoProperty(string $field): string
	{
		return StringHelper::snake2camel($field, true);
	}

	/**
	 * Coerce a raw ORM value into the target BackedEnum, failing loud on bad data.
	 *
	 * Three behaviours, in order:
	 *  1. Value is already an instance of the target enum → return as-is.
	 *     ORM normally yields scalars from the column, but custom field handlers
	 *     can pre-hydrate the enum; without this guard tryFrom() would TypeError
	 *     because its signature accepts only `int|string`.
	 *  2. Value matches the enum's backing type → tryFrom() it. If tryFrom returns
	 *     null (stored value does not correspond to any case → likely corrupt or
	 *     stale data after an enum-case removal), throw a {@see \UnexpectedValueException}
	 *     instead of silently substituting null. Silent null would either mask
	 *     data corruption on nullable fields or blow up later on non-nullable
	 *     ones — both worse than failing fast here with a contextful message.
	 *  3. Value does not match the backing type (e.g. non-numeric string in an
	 *     int-backed column) → also throw, with the same reasoning. Letting
	 *     tryFrom() raise TypeError directly would surface a generic engine
	 *     error rather than the actionable "column X holds bad data" message.
	 *
	 * @param class-string<\BackedEnum> $enumClass
	 */
	private static function coerceOrmValueToEnum(string $enumClass, mixed $value, string $dtoProperty): \BackedEnum
	{
		if ($value instanceof $enumClass)
		{
			return $value;
		}

		$backingType = null;
		try
		{
			$backingType = (new ReflectionClass($enumClass))->isEnum()
				? (new \ReflectionEnum($enumClass))->getBackingType()?->getName()
				: null;
		}
		catch (\ReflectionException)
		{
			// Fall through to the type-mismatch path below — failing here would
			// hide the underlying schema issue (enum class disappeared from the
			// process).
		}

		// Match PHP's BackedEnum::tryFrom() acceptance contract exactly: it
		// raises TypeError only for non-numeric strings on int-backed enums;
		// every other scalar combination (int into str-backed, numeric string
		// into int-backed) is accepted and resolved or returns null.
		$assignable = match ($backingType)
		{
			'int' => is_int($value) || (is_string($value) && is_numeric($value)),
			'string' => is_string($value) || is_int($value),
			default => false,
		};

		if ($assignable)
		{
			$enumValue = $enumClass::tryFrom($value);
			if ($enumValue !== null)
			{
				return $enumValue;
			}
		}

		throw new \UnexpectedValueException(sprintf(
			'ORM value %s for DTO property "%s" cannot be mapped to enum %s (backing type: %s). Storage likely holds stale or corrupt data; investigate the column instead of swallowing the mismatch.',
			var_export($value, true),
			$dtoProperty,
			$enumClass,
			$backingType ?? 'unknown',
		));
	}

	public static function mapDtoPropertyToOrmField(string $property): string
	{
		return strtoupper(StringHelper::camel2snake($property));
	}

	protected static function mapAggregateFunction(string $value): string
	{
		$availableMethods = ['sum', 'avg', 'max', 'min', 'count', 'countDistinct'];

		if (in_array($value, $availableMethods, true))
		{
			return $value !== 'countDistinct' ? $value : 'CountDistinct';
		}
		throw new SystemException('Unsupported aggregation method: ' . $value . '. Use one of ' . join(', ', $availableMethods));
	}

	/**
	 * @throws UnknownDtoPropertyException
	 * @throws InvalidPaginationException
	 * @throws ArgumentException
	 * @throws InvalidSelectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getIdsByFilter(FilterStructure $filter): array
	{
		$query = $this->getQuery(
			select: SelectStructure::create(['id'], $this->dtoClass, new ListRequest($this->dtoClass)),
			filter: $filter,
			page: PaginationStructure::create(['limit' => PaginationStructure::MAX_LIMIT]),
		);

		$rowsCursor = $query->exec();

		$ids = [];

		foreach ($rowsCursor as $row)
		{
			if (isset($row['ID']))
			{
				$ids[] = (int)$row['ID'];
			}
		}

		return $ids;
	}
}
