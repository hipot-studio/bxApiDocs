<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Service;

use Bitrix\Main\DB\Order as DbOrder;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Bitrix\Sale\Basket;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Infrastructure\Rest\Support\CreationFieldsValidator;
use Bitrix\Sale\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Sale\Infrastructure\Rest\Support\EditableFieldsFilter;
use Bitrix\Sale\Infrastructure\Rest\Support\FilterStructureSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\IdentifierSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\OrderFormBuilder;
use Bitrix\Sale\Infrastructure\Rest\Support\PaginationSupport;
use Bitrix\Sale\Order;
use Bitrix\Sale\Registry;

/**
 * A basket position addressed on its own: one position and the positions of one order.
 *
 * Rights are checked first, before anything is loaded, by the same rule the order resource uses:
 * the position carries no access rules of its own, and the order behind it is not checked for
 * ownership either - parity with V1.
 *
 * A position is reached the way V1 reached it: the basket row answers which order owns it, and the
 * order is then loaded by the domain. The element itself comes from the projection of the order
 * service and never from a second one, so a position read here equals the same position inside
 * `sale.order.get`, field by field.
 *
 * Two narrowings against V1 are deliberate. A basket row whose order does not load is "not found"
 * rather than the fatal error of V1, and a list is a list of one order: the only condition its
 * filter takes is the equality naming that order.
 *
 * Writing keeps the same rule: one position is written by writing its order, through the assembly
 * the order resource uses ({@see OrderService::buildOrder()}), told to keep the rest of the basket.
 * The whole set of what the input got wrong goes out at once - V1 answered with the first error
 * and stopped.
 *
 * A position holding goods of the catalog is written by the same path, and differs in two things
 * only: the module `catalog` is required of the call, and a creation puts the provider of the
 * catalog on the position. Which of the two paths a call takes is decided by the resource it came
 * to and never by the payload.
 */
final class BasketItemService
{
	private const PARENT_PROPERTY = 'orderId';

	private const ID_PROPERTY = 'id';

	private const CURRENCY_PROPERTY = 'currency';

	private const ADD_GROUP = 'add';

	private const UPDATE_GROUP = 'update';

	public function __construct(
		private readonly AccessSupport $accessSupport,
		private readonly OrderService $orderService,
	)
	{
	}

	public function getBasketItem(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensureOrderRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$order = $this->loadOrderOfPosition($id);
		if ($order === null)
		{
			return ServiceResult::notFound($id);
		}

		$fields = $this->getFields($select);

		// The identifier is what tells the positions of the basket apart, so the projection is
		// asked for it even when the caller did not, and the element is trimmed back afterwards.
		$items = $this->orderService->buildBasketItems(
			$order,
			$this->withProperties($fields, [self::ID_PROPERTY]),
			withComputedOnDemand: false,
		);

		$item = $this->findItem($items, $id);
		if ($item === null)
		{
			return ServiceResult::notFound($id);
		}

		[$item] = $this->orderService->countBasketItemComputedOnDemand($order, [$item], $fields);

		return ServiceResult::ok(['item' => $this->trim($item, $fields)]);
	}

	public function getBasketItemList(
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

		$orderId = $this->readOrderId($filter);
		if ($orderId instanceof Result)
		{
			return $orderId;
		}

		$domainOrder = $this->loadOrder($orderId);
		if ($domainOrder === null)
		{
			return ServiceResult::notFound($orderId);
		}

		$fields = $this->getFields($select);
		$sorting = $order?->getList() ?? [];

		// The basket of one order is small enough to be ordered and cut where it already is; the
		// domain hands it over sorted by `SORT`, then by identifier, and that order stands when the
		// caller names none. Sorting by a property outside the select needs it projected, so it is
		// asked for and trimmed back, exactly as the identifier is above.
		$items = $this->orderService->buildBasketItems(
			$domainOrder,
			$this->withProperties($fields, [...array_keys($sorting), self::ID_PROPERTY]),
			withComputedOnDemand: false,
		);

		$items = array_slice(
			$this->sort($items, $sorting),
			PaginationSupport::getOffset($pagination),
			PaginationSupport::getLimit($pagination),
		);

		// No value counted on demand is sortable, so the page is cut first and only it pays for them.
		$items = $this->orderService->countBasketItemComputedOnDemand($domainOrder, $items, $fields);

		return ServiceResult::ok([
			'items' => array_map(fn(array $item): array => $this->trim($item, $fields), $items),
		]);
	}

	/**
	 * The order is addressed by the request rather than by the payload: the data type keeps
	 * `orderId` read only, so that the requirement of an address does not leak into the basket
	 * nested in `sale.order.add`, where the order is not addressed at all.
	 *
	 * @param mixed $orderId the address as the transport carried it, unread
	 */
	public function addBasketItem(mixed $orderId, BasketItemDto $dto): Result
	{
		return $this->add($orderId, $dto, forCatalogProduct: false);
	}

	/**
	 * The same creation, with the provider of the catalog put on the position: the resource the call
	 * came to is what chooses the provider, so nothing about it is read from the payload.
	 *
	 * @param mixed $orderId the address as the transport carried it, unread
	 */
	public function addCatalogProduct(mixed $orderId, BasketItemDto $dto): Result
	{
		return $this->add($orderId, $dto, forCatalogProduct: true);
	}

	private function add(mixed $orderId, BasketItemDto $dto, bool $forCatalogProduct): Result
	{
		$allowed = $this->ensureWriteAllowed($forCatalogProduct);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		$parentId = IdentifierSupport::readPositive($orderId);
		$order = $parentId === null ? null : $this->loadOrder($parentId);
		if ($parentId !== null && $order === null)
		{
			return ServiceResult::notFound($parentId);
		}

		$errors = $this->findInputErrors($dto, self::ADD_GROUP, $order);
		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		/** @var Order $order the address was checked above */
		$assembled = $this->assemble($dto, $order, $this->getPositionColumns($forCatalogProduct));
		if (!$assembled->isSuccess())
		{
			return $assembled;
		}

		/** @var Order $built */
		$built = $assembled->getData()['order'];

		// The position being created is the one the basket does not know yet, and after the save
		// every position knows its number: which one it was has to be remembered before.
		$index = $this->findNewPositionIndex($built);

		$saved = $this->orderService->saveBuiltOrder($built);
		if (!$saved->isSuccess())
		{
			return $saved;
		}

		$id = $index === null ? null : $this->readPositionId($built, $index);
		if ($id === null)
		{
			return ServiceResult::validation(new Error('The basket position was not saved'));
		}

		return $this->getBasketItem($id);
	}

	public function updateBasketItem(int $id, BasketItemDto $dto): Result
	{
		return $this->update($id, $dto, forCatalogProduct: false);
	}

	/**
	 * The same change, against the resource of a catalog product. The provider is not touched here:
	 * it belongs to the position since its creation, and V1 did not re-assign it on a change either.
	 */
	public function updateCatalogProduct(int $id, BasketItemDto $dto): Result
	{
		return $this->update($id, $dto, forCatalogProduct: true);
	}

	/**
	 * The identity of what is being changed is the server's to decide: the position is the one the
	 * request addressed, and its order is the one it belongs to. Whatever the payload says about
	 * either is worth nothing - a position cannot move to another order and cannot be swapped for
	 * a neighbouring one through the body of the call.
	 */
	private function update(int $id, BasketItemDto $dto, bool $forCatalogProduct): Result
	{
		$allowed = $this->ensureWriteAllowed($forCatalogProduct);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		$order = $this->loadOrderOfPosition($id);
		// A row the basket of its own order does not carry is no position of it: handing that
		// number to the domain would not change a position but create one.
		if ($order === null || $order->getBasket()?->getItemById($id) === null)
		{
			return ServiceResult::notFound($id);
		}

		EditableFieldsFilter::dropFieldsNotEditableIn($dto, self::UPDATE_GROUP);
		$dto->id = $id;

		$errors = $this->findInputErrors($dto, self::UPDATE_GROUP, $order);
		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		$assembled = $this->assemble($dto, $order);
		if (!$assembled->isSuccess())
		{
			return $assembled;
		}

		$saved = $this->orderService->saveBuiltOrder($assembled->getData()['order']);

		return $saved->isSuccess() ? $this->getBasketItem($id) : $saved;
	}

	/**
	 * Removal goes through the domain, as in V1: the cascades and the recount of the sums live
	 * there, and the order is saved only when the position actually left it.
	 */
	public function deleteBasketItem(int $id): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$order = $this->loadOrderOfPosition($id);
		$position = $order?->getBasket()?->getItemById($id);
		if ($order === null || $position === null)
		{
			return ServiceResult::notFound($id);
		}

		$deleted = $position->delete();
		if (!$deleted->isSuccess())
		{
			return ServiceResult::validation($deleted->getErrors());
		}

		$saved = $this->orderService->saveBuiltOrder($order);

		return $saved->isSuccess() ? ServiceResult::ok() : $saved;
	}

	/**
	 * What a write of a position needs before it reaches anything at all.
	 *
	 * The right of the module comes first and the module of the goods second, on purpose: a caller
	 * who may not write learns nothing about what is installed on the portal.
	 */
	private function ensureWriteAllowed(bool $forCatalogProduct): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess() || !$forCatalogProduct)
		{
			return $access;
		}

		return $this->ensureCatalogModule();
	}

	/**
	 * A catalog product is a position counted by the catalog, so without that module the call is
	 * refused where V1 refused it - before the order is even loaded.
	 */
	private function ensureCatalogModule(): Result
	{
		if (!Loader::includeModule('catalog'))
		{
			return ServiceResult::validation(
				new Error('A catalog product requires the module catalog to be installed'),
			);
		}

		return ServiceResult::ok();
	}

	/**
	 * The provider of the position, named by the resource the call came to rather than by the
	 * payload: the data type carries no provider at all (the module and the class of the provider
	 * are not fields of this surface), so this is the single place a position learns whose goods it
	 * holds. A change does not re-assign it - parity with V1.
	 *
	 * @return array<string, mixed>
	 */
	private function getPositionColumns(bool $forCatalogProduct): array
	{
		if (!$forCatalogProduct)
		{
			return [];
		}

		return [
			'MODULE' => 'catalog',
			'PRODUCT_PROVIDER_CLASS' => \Bitrix\Catalog\Product\Basket::getDefaultProviderName(),
		];
	}

	/**
	 * The single condition a list of positions takes: the equality naming the order. Anything else
	 * - a disjunction, a negation, another operator, another field, or a second condition - is
	 * refused instead of being narrowed down to the part this service understands.
	 *
	 * @return int|Result the order named by the filter, or the refusal
	 */
	private function readOrderId(?FilterStructure $filter): int|Result
	{
		if ($filter === null)
		{
			return ServiceResult::validation(
				new Error('The filter must name the order by its "' . self::PARENT_PROPERTY . '"'),
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
				new Error('The filter takes exactly one condition, the "' . self::PARENT_PROPERTY . '" of the order'),
			);
		}

		$orderId = IdentifierSupport::readPositive($condition->getRightOperand());
		if ($orderId === null)
		{
			return ServiceResult::validation(
				new Error('The "' . self::PARENT_PROPERTY . '" of the filter must name an order'),
			);
		}

		return $orderId;
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

	/**
	 * What the input got wrong, all of it.
	 *
	 * V1 answered with the first error and returned, so a payload with two mistakes took two calls
	 * to fix. Here the checks add up and leave together. The one early return left is the one
	 * without which the next check has nothing to work on: the currency of an order nobody named
	 * cannot be compared with anything.
	 *
	 * @param Order|null $order the order the position lives in; a create that named none has null
	 * @return list<Error>
	 */
	private function findInputErrors(BasketItemDto $dto, string $group, ?Order $order): array
	{
		$errors = CreationFieldsValidator::validate($dto, $group, rootIsCreatedElement: $group === self::ADD_GROUP);

		if ($order === null)
		{
			$errors[] = new Error(
				'Field "' . self::PARENT_PROPERTY . '" is required and must name an order',
			);

			return $errors;
		}

		// A position belongs to the money of its order. The comparison is the strict one of V1: a
		// currency spelled in another case is another currency, and the domain would silently take
		// the sums of the order for it.
		$values = DtoFieldsConverter::getInitializedValues($dto);
		if (
			array_key_exists(self::CURRENCY_PROPERTY, $values)
			&& (string)$values[self::CURRENCY_PROPERTY] !== (string)$order->getCurrency()
		)
		{
			$errors[] = new Error(
				'Field "' . self::CURRENCY_PROPERTY . '" must be the currency of the order',
			);
		}

		return $errors;
	}

	/**
	 * The domain assembly of the order around the one position, and the check that needs it.
	 *
	 * The catalog rights of `crm` are the one rule of the write that cannot be answered before the
	 * order is built: it reads the assembled positions to tell a price the caller changed from one
	 * the caller merely repeated. V1 checked it in the same place and took the first of its errors;
	 * here the whole set leaves.
	 *
	 * @param array<string, mixed> $positionColumns domain columns the resource puts on the position
	 *        on top of what the client sent
	 * @return Result data: `order` is the assembled, not yet saved order
	 */
	private function assemble(BasketItemDto $dto, Order $order, array $positionColumns = []): Result
	{
		$orderDto = $this->wrapInOrder($dto);
		$built = $this->orderService->buildOrder(
			$this->buildForm($orderDto, $order, $positionColumns),
			$orderDto,
			isExistingOrder: true,
			deleteBasketItemsIfNotExists: false,
		);
		if (!$built->isSuccess())
		{
			return $built;
		}

		$rights = $this->checkCatalogRights($built->getData()['order']);

		return $rights->isSuccess() ? $built : $rights;
	}

	/**
	 * The rule of the module `crm` over the prices of catalog goods, called the way V1 called it -
	 * through the domain, with no reading of the rights in this layer. Without `crm` there is no
	 * rule to apply.
	 */
	private function checkCatalogRights(Order $order): Result
	{
		if (!ModuleManager::isModuleInstalled('crm') || !Loader::includeModule('crm'))
		{
			return ServiceResult::ok();
		}

		if (!$order instanceof \Bitrix\Crm\Order\Order)
		{
			return ServiceResult::ok();
		}

		$checked = \Bitrix\Crm\Service\Container::getInstance()
			->getProductRowChecker()
			->checkOrderCatalogRights($order)
		;

		return $checked->isSuccess() ? ServiceResult::ok() : ServiceResult::validation($checked->getErrors());
	}

	/**
	 * The position the assembly has just put into the basket: the only one of the basket that has
	 * no number of its own yet. It is named by the internal index, the way the domain tells the
	 * positions of a basket apart before they are saved.
	 */
	private function findNewPositionIndex(Order $order): ?int
	{
		/** @var BasketItem $basketItem */
		foreach ($order->getBasket() ?? [] as $basketItem)
		{
			if ((int)$basketItem->getId() <= 0)
			{
				return $basketItem->getInternalIndex();
			}
		}

		return null;
	}

	private function readPositionId(Order $order, int $index): ?int
	{
		$basketItem = $order->getBasket()?->getItemByIndex($index);

		return $basketItem === null ? null : IdentifierSupport::readPositive($basketItem->getId());
	}

	/** The input of the order the position belongs to, carrying nothing but that position. */
	private function wrapInOrder(BasketItemDto $dto): OrderDto
	{
		$positions = new DtoCollection(BasketItemDto::class);
		$positions->add($dto);

		$orderDto = OrderDto::create();
		$orderDto->basketItems = $positions;

		return $orderDto;
	}

	/**
	 * The form of that order.
	 *
	 * The write of a position is the write of its order, told to keep the rest of the basket: the
	 * form is the ordinary order form of the layer ({@see OrderFormBuilder}) with a basket of one,
	 * and the domain then does to that one position exactly what it does to a position inside
	 * `sale.order.update`. A second assembly of its own would drift from the order at the first
	 * field added to either.
	 *
	 * Two keys the collection rule cannot answer for are named here. The identifier makes the
	 * domain change the order instead of creating one, and the person type is fixed at creation:
	 * left out of the form it is not kept but replaced with the first person type of the site.
	 *
	 * @param array<string, mixed> $positionColumns domain columns of the resource, overriding what
	 *        the form carries: the client names no provider, so there is nothing of its to lose
	 * @return array<string, mixed>
	 */
	private function buildForm(OrderDto $orderDto, Order $order, array $positionColumns = []): array
	{
		$form = OrderFormBuilder::build($orderDto, $order);
		$form['ID'] = (int)$order->getId();
		$form['PERSON_TYPE_ID'] = (int)$order->getPersonTypeId();

		if ($positionColumns !== [])
		{
			foreach ($form['PRODUCT'] ?? [] as $key => $position)
			{
				$form['PRODUCT'][$key] = $positionColumns + $position;
			}
		}

		return $form;
	}

	/** The whole order behind one position, the single way this resource reaches a position. */
	private function loadOrderOfPosition(int $basketItemId): ?Order
	{
		$orderId = $this->findOrderId($basketItemId);

		return $orderId === null ? null : $this->loadOrder($orderId);
	}

	/** The order owning the basket row, read as V1 read it: one column of one row. */
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

		$numericProperties = $this->findNumericProperties(array_keys($sorting));

		usort(
			$items,
			function (array $left, array $right) use ($sorting, $numericProperties): int
			{
				foreach ($sorting as $property => $direction)
				{
					$comparison = $this->compare(
						$left[$property] ?? null,
						$right[$property] ?? null,
						$numericProperties[$property] ?? false,
					);
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
	 * Which of the properties being sorted by hold numbers, told by the type the schema declares
	 * for each of them ({@see BasketItemDto}) and read through the field collection of the core.
	 *
	 * The type has to come from the declaration and never from the pair of values in hand: a
	 * comparison chosen per pair is intransitive on names as ordinary as "2", "10" and "1a" -
	 * "2" < "10" as numbers, "10" < "1a" and "1a" < "2" as strings - and usort() has no consistent
	 * order left to build, so the pages of the list come back shuffled.
	 *
	 * @param list<int|string> $properties
	 * @return array<string, bool> property => compared as a number
	 */
	private function findNumericProperties(array $properties): array
	{
		$fields = BasketItemDto::create()->getFields();

		$numeric = [];
		foreach ($properties as $property)
		{
			$type = $fields[(string)$property]?->getPropertyType();
			$numeric[(string)$property] = $type === 'int' || $type === 'float';
		}

		return $numeric;
	}

	/** The projection carries domain values, where a number may still be spelled as a string. */
	private function compare(mixed $left, mixed $right, bool $asNumber): int
	{
		if ($asNumber)
		{
			return (float)$left <=> (float)$right;
		}

		return strcmp((string)$left, (string)$right);
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
