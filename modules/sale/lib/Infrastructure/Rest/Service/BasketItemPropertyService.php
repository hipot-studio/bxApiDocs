<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Service;

use Bitrix\Main\DB\Order as DbOrder;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Bitrix\Sale\Basket;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\BasketPropertyItem;
use Bitrix\Sale\BasketPropertyItemBase;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemPropertyDto;
use Bitrix\Sale\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Sale\Infrastructure\Rest\Support\EditableFieldsFilter;
use Bitrix\Sale\Infrastructure\Rest\Support\FilterStructureSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\IdentifierSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\PaginationSupport;
use Bitrix\Sale\Order;
use Bitrix\Sale\Registry;
use Bitrix\Sale\Result as SaleResult;

/**
 * Properties of a basket position as a standalone resource.
 * Writes go through the property collection of the position rather than the order assembly, which calls
 * `BasketPropertiesCollectionBase::redefine()` and drops every property the payload omits.
 */
final class BasketItemPropertyService
{
	/** The field addressing the position. */
	private const PARENT_PROPERTY = 'basketItemId';

	private const ID_PROPERTY = 'id';

	/** The part of a position the projection is asked for. */
	private const PROPERTIES_PROPERTY = 'properties';

	private const UPDATE_GROUP = 'update';

	/** Properties whose columns are declared NOT NULL, so a null would end up stored as an empty value. */
	private const NOT_NULL_PROPERTIES = ['name', 'sort'];

	public function __construct(
		private readonly AccessSupport $accessSupport,
		private readonly OrderService $orderService,
	)
	{
	}

	public function getProperty(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensureOrderRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$basketItemId = $this->findBasketItemId($id);
		$order = $basketItemId === null ? null : $this->loadOrderOfPosition($basketItemId);
		if ($basketItemId === null || $order === null)
		{
			return ServiceResult::notFound($id);
		}

		$fields = $this->getFields($select);

		// The identifier is projected even when not selected and trimmed from the answer afterwards.
		$properties = $this->projectProperties(
			$order,
			$basketItemId,
			$this->withProperties($fields, [self::ID_PROPERTY]),
		);

		$item = $properties === null ? null : $this->findItem($properties, $id);
		if ($item === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::ok(['item' => $this->trim($item, $fields)]);
	}

	public function getPropertyList(
		?SelectStructure $select = null,
		?FilterStructure $filter = null,
		?OrderStructure $order = null,
		?PaginationStructure $pagination = null,
	): Result
	{
		$access = $this->accessSupport->ensureOrderRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if (!PaginationSupport::isLimitAcceptable($pagination))
		{
			return ServiceResult::validation(new Error('The pagination limit must be a positive number'));
		}

		$basketItemId = $this->readBasketItemId($filter);
		if ($basketItemId instanceof Result)
		{
			return $basketItemId;
		}

		$domainOrder = $this->loadOrderOfPosition($basketItemId);
		if ($domainOrder === null)
		{
			return ServiceResult::notFound($basketItemId);
		}

		$fields = $this->getFields($select);
		$sorting = $order?->getList() ?? [];

		// Sort fields outside the select are projected too and trimmed from the answer afterwards.
		$properties = $this->projectProperties(
			$domainOrder,
			$basketItemId,
			$this->withProperties($fields, array_keys($sorting)),
		);
		if ($properties === null)
		{
			return ServiceResult::notFound($basketItemId);
		}

		$properties = array_slice(
			$this->sort($properties, $sorting),
			PaginationSupport::getOffset($pagination),
			PaginationSupport::getLimit($pagination),
		);

		return ServiceResult::ok([
			'items' => array_map(fn(array $item): array => $this->trim($item, $fields), $properties),
		]);
	}

	/**
	 * @param mixed $basketItemId raw request value; `basketItemId` is read only in the data type
	 */
	public function addProperty(mixed $basketItemId, BasketItemPropertyDto $dto): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$parentId = IdentifierSupport::readPositive($basketItemId);
		$order = $parentId === null ? null : $this->loadOrderOfPosition($parentId);
		$position = $parentId === null ? null : $order?->getBasket()?->getItemById($parentId);
		if ($parentId !== null && $position === null)
		{
			return ServiceResult::notFound($parentId);
		}

		$errors = $this->findInputErrors($parentId !== null);
		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		/** @var BasketItem $position the address was answered for above */
		$property = $position->getPropertyCollection()->createItem();

		$written = $this->write($property, $dto);
		if (!$written->isSuccess())
		{
			return $written;
		}

		/** @var Order $order the position came out of it */
		$saved = $this->orderService->saveBuiltOrder($order);
		if (!$saved->isSuccess())
		{
			return $saved;
		}

		// The domain sets the new row identifier on the saved element.
		$id = IdentifierSupport::readPositive($property->getId());
		if ($id === null)
		{
			return ServiceResult::validation(new Error('The property of the basket position was not saved'));
		}

		return $this->getProperty($id);
	}

	/**
	 * Only the keys present in the payload are written; `name`, `code` and `value` are required on creation alone.
	 */
	public function updateProperty(int $id, BasketItemPropertyDto $dto): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$located = $this->locate($id);
		if ($located === null)
		{
			return ServiceResult::notFound($id);
		}

		[$order, $property] = $located;

		EditableFieldsFilter::dropFieldsNotEditableIn($dto, self::UPDATE_GROUP);

		$written = $this->write($property, $dto);
		if (!$written->isSuccess())
		{
			return $written;
		}

		$saved = $this->orderService->saveBuiltOrder($order);

		return $saved->isSuccess() ? $this->getProperty($id) : $saved;
	}

	/**
	 * The element leaves its collection, whose save removes the row
	 * ({@see \Bitrix\Sale\BasketPropertiesCollectionBase::save()}); deleting the row directly would leave
	 * the loaded order stale. Properties set by the catalog are not protected from removal.
	 */
	public function deleteProperty(int $id): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$located = $this->locate($id);
		if ($located === null)
		{
			return ServiceResult::notFound($id);
		}

		[$order, $property] = $located;

		$refusal = $this->findRefusal($property->delete());
		if ($refusal !== null)
		{
			return $refusal;
		}

		$saved = $this->orderService->saveBuiltOrder($order);

		return $saved->isSuccess() ? ServiceResult::ok() : $saved;
	}

	/**
	 * @return list<Error>
	 */
	private function findInputErrors(bool $hasParent): array
	{
		$errors = [];

		if (!$hasParent)
		{
			$errors[] = new Error(
				'Field "' . self::PARENT_PROPERTY . '" is required and must name a basket position',
			);
		}

		return $errors;
	}

	private function write(BasketPropertyItemBase $property, BasketItemPropertyDto $dto): Result
	{
		$refusal = $this->findNullRefusal($dto);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$refusal = $this->findRefusal($property->setFields($this->toDomainFields($dto)));
		if ($refusal !== null)
		{
			return $refusal;
		}

		// Verified before the save, which silently cuts an overlong value to the column size.
		return $this->findRefusal($property->verify()) ?? ServiceResult::ok();
	}

	/**
	 * A null sent for a NOT NULL column would reach the save untouched: every field of the data type is
	 * nullable, and `verify()` measures a value only when it is not empty. An absent key is not a null
	 * and still leaves the field as it is.
	 */
	private function findNullRefusal(BasketItemPropertyDto $dto): ?Result
	{
		$values = DtoFieldsConverter::getInitializedValues($dto);

		$errors = [];
		foreach (self::NOT_NULL_PROPERTIES as $field)
		{
			if (array_key_exists($field, $values) && $values[$field] === null)
			{
				$errors[] = new Error('Field "' . $field . '" cannot be set to null');
			}
		}

		return $errors === [] ? null : ServiceResult::validation($errors);
	}

	/**
	 * Narrowed to the available fields of the domain entity, so `ID` and `BASKET_ID` stay server-owned
	 * and {@see \Bitrix\Sale\Internals\Entity::setField()} never throws on an unknown field.
	 *
	 * @return array<string, mixed>
	 */
	private function toDomainFields(BasketItemPropertyDto $dto): array
	{
		$fields = DtoFieldsConverter::toDomainFields(
			$dto,
			AbstractOrderMapper::forDto(BasketItemPropertyDto::class)->getColumnMap(),
		);

		/** @var class-string<BasketPropertyItem> $propertyClass */
		$propertyClass = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->getBasketPropertyItemClassName();

		return array_intersect_key($fields, $propertyClass::getAvailableFields());
	}

	/**
	 * Warnings count as a refusal: the length limit of a value is raised as a warning,
	 * and the save would silently cut the string.
	 */
	private function findRefusal(Result $result): ?Result
	{
		$errors = $result->getErrors();
		if ($result instanceof SaleResult)
		{
			$errors = array_merge($errors, $result->getWarnings());
		}

		return $errors === [] ? null : ServiceResult::validation(array_values($errors));
	}

	/**
	 * The element is taken from the loaded order rather than the table, since only the order save persists it.
	 *
	 * @return array{Order, BasketPropertyItemBase}|null
	 */
	private function locate(int $id): ?array
	{
		$basketItemId = $this->findBasketItemId($id);
		$order = $basketItemId === null ? null : $this->loadOrderOfPosition($basketItemId);
		$position = $basketItemId === null ? null : $order?->getBasket()?->getItemById($basketItemId);
		$property = $position?->getPropertyCollection()?->getItemById($id);

		return $order !== null && $property instanceof BasketPropertyItemBase ? [$order, $property] : null;
	}

	/**
	 * Taken from {@see OrderService::buildBasketItems()}, so a property matches the one nested in its position.
	 *
	 * @param array<int|string, mixed> $propertyFields structured select of a property; empty means
	 *        every field
	 * @return list<array<string, mixed>>|null null when the order carries no such position
	 */
	private function projectProperties(Order $order, int $basketItemId, array $propertyFields): ?array
	{
		$positions = $this->orderService->buildBasketItems(
			$order,
			[self::ID_PROPERTY, self::PROPERTIES_PROPERTY => $propertyFields],
		);

		foreach ($positions as $position)
		{
			if (IdentifierSupport::readPositive($position[self::ID_PROPERTY] ?? null) === $basketItemId)
			{
				return $position[self::PROPERTIES_PROPERTY] ?? [];
			}
		}

		return null;
	}

	/**
	 * The only accepted filter is a single equality on `basketItemId`; anything else is refused, not narrowed.
	 *
	 * @return int|Result the position named by the filter, or the refusal
	 */
	private function readBasketItemId(?FilterStructure $filter): int|Result
	{
		if ($filter === null)
		{
			return ServiceResult::validation(
				new Error('The filter must name the basket position by its "' . self::PARENT_PROPERTY . '"'),
			);
		}

		$unsupported = FilterStructureSupport::findUnsupportedSemantics($filter);
		if ($unsupported !== null)
		{
			return ServiceResult::validation($unsupported);
		}

		$conditions = $this->collectConditions($filter);
		$condition = count($conditions) === 1 ? $conditions[0] : null;
		if (
			$condition === null
			|| $condition->getLeftOperand() !== self::PARENT_PROPERTY
			|| $condition->getOperator() !== Operator::Equal
		)
		{
			return ServiceResult::validation(
				new Error(
					'The filter takes exactly one condition, the "' . self::PARENT_PROPERTY . '" of the position',
				),
			);
		}

		$basketItemId = IdentifierSupport::readPositive($condition->getRightOperand());
		if ($basketItemId === null)
		{
			return ServiceResult::validation(
				new Error('The "' . self::PARENT_PROPERTY . '" of the filter must name a basket position'),
			);
		}

		return $basketItemId;
	}

	/**
	 * A simple filter arrives as a group holding one condition, so the leaves are collected rather
	 * than read off the top level. Disjunction and negation are already refused by then.
	 *
	 * @return list<Condition>
	 */
	private function collectConditions(FilterStructure $filter): array
	{
		$conditions = [];
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$conditions = array_merge($conditions, $this->collectConditions($condition));
			}
			elseif ($condition instanceof Condition)
			{
				$conditions[] = $condition;
			}
		}

		return $conditions;
	}

	private function findBasketItemId(int $propertyId): ?int
	{
		/** @var class-string<BasketPropertyItem> $propertyClass */
		$propertyClass = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->getBasketPropertyItemClassName();

		$row = $propertyClass::getList([
			'select' => ['BASKET_ID'],
			'filter' => ['=ID' => $propertyId],
			'limit' => 1,
		])->fetch();

		if (!is_array($row))
		{
			return null;
		}

		return IdentifierSupport::readPositive($row['BASKET_ID'] ?? null);
	}

	/** The whole order behind one position, the single way this resource reaches a property. */
	private function loadOrderOfPosition(int $basketItemId): ?Order
	{
		$orderId = $this->findOrderId($basketItemId);

		return $orderId === null ? null : $this->loadOrder($orderId);
	}

	/**
	 * A basket row without an order is the cart of a visitor who has not ordered yet, so it names nothing.
	 */
	private function findOrderId(int $basketItemId): ?int
	{
		/** @var class-string<Basket> $basketClass */
		$basketClass = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->getBasketClassName();

		$row = $basketClass::getList([
			'select' => ['ORDER_ID'],
			'filter' => ['=ID' => $basketItemId],
			'limit' => 1,
		])->fetch();

		if (!is_array($row))
		{
			return null;
		}

		return IdentifierSupport::readPositive($row['ORDER_ID'] ?? null);
	}

	private function loadOrder(int $orderId): ?Order
	{
		/** @var class-string<Order> $orderClass */
		$orderClass = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->getOrderClassName();

		$order = $orderClass::load($orderId);

		return $order instanceof Order ? $order : null;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>|null
	 */
	private function findItem(array $items, int $id): ?array
	{
		foreach ($items as $item)
		{
			if (IdentifierSupport::readPositive($item[self::ID_PROPERTY] ?? null) === $id)
			{
				return $item;
			}
		}

		return null;
	}

	/**
	 * Without sorting the domain load order is kept, so the list matches the properties inside the position.
	 * The comparison is numeric because both sortable fields, `sort` and `id`, are integers.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param array<string, string> $sorting property => direction, a value of `Main\DB\Order`
	 * @return list<array<string, mixed>>
	 */
	private function sort(array $items, array $sorting): array
	{
		if ($sorting === [])
		{
			return $items;
		}

		usort(
			$items,
			static function (array $left, array $right) use ($sorting): int
			{
				foreach ($sorting as $property => $direction)
				{
					$comparison = (float)($left[$property] ?? null) <=> (float)($right[$property] ?? null);
					if ($comparison !== 0)
					{
						return $direction === DbOrder::Desc->value ? -$comparison : $comparison;
					}
				}

				return 0;
			},
		);

		return $items;
	}

	/**
	 * The properties the answer needs on top of the ones it was asked for. An empty select already
	 * means every field, so it is left alone.
	 *
	 * @param array<int|string, mixed> $fields
	 * @param list<int|string> $properties
	 * @return array<int|string, mixed>
	 */
	private function withProperties(array $fields, array $properties): array
	{
		if ($fields === [])
		{
			return [];
		}

		foreach ($properties as $property)
		{
			if (!$this->isSelected((string)$property, $fields))
			{
				$fields[] = (string)$property;
			}
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<int|string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function trim(array $item, array $fields): array
	{
		if ($fields === [])
		{
			return $item;
		}

		return array_filter(
			$item,
			fn(int|string $property): bool => $this->isSelected((string)$property, $fields),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/**
	 * @param array<int|string, mixed> $fields
	 */
	private function isSelected(string $property, array $fields): bool
	{
		return $fields === []
			|| in_array($property, $fields, true)
			|| array_key_exists($property, $fields);
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private function getFields(?SelectStructure $select): array
	{
		return $select?->getStructuredList() ?? [];
	}
}
