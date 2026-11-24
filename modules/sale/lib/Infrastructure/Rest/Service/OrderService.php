<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\LogicException;
use Bitrix\Rest\V3\Structure\CursorStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Delivery;
use Bitrix\Sale\DeliveryStatus;
use Bitrix\Sale\Helpers\Order\Builder\BuildingException;
use Bitrix\Sale\Helpers\Order\Builder\OrderBuilder;
use Bitrix\Sale\Helpers\Order\Builder\OrderBuilderRest;
use Bitrix\Sale\Helpers\Order\Builder\SettingsContainer;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemPropertyDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemReservationDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ClientDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\PaymentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\PropertyValueDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\RequisiteLinkDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentItemDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\TradeBindingDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Infrastructure\Rest\Support\CreationFieldsValidator;
use Bitrix\Sale\Infrastructure\Rest\Support\DeductedShipmentSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Sale\Infrastructure\Rest\Support\EditableFieldsFilter;
use Bitrix\Sale\Infrastructure\Rest\Support\ElementIdentity;
use Bitrix\Sale\Infrastructure\Rest\Support\ElementLocator;
use Bitrix\Sale\Infrastructure\Rest\Support\ExplicitNullSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\FilterStructureSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\IdentifierSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Sale\Infrastructure\Rest\Support\OrderFormBuilder;
use Bitrix\Sale\Infrastructure\Rest\Support\PaginationSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\PreflightCheckSupport;
use Bitrix\Sale\Infrastructure\Rest\Support\SyncEventSuppression;
use Bitrix\Sale\Infrastructure\Rest\Support\TailSupport;
use Bitrix\Sale\Internals\PersonTypeTable;
use Bitrix\Sale\Internals\StatusTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\OrderStatus;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\PersonType;
use Bitrix\Sale\Property;
use Bitrix\Sale\PropertyValue;
use Bitrix\Sale\Registry;
use Bitrix\Sale\Reservation\Internals\BasketReservationTable;
use Bitrix\Sale\Shipment;
use Bitrix\Sale\TradeBindingEntity;
use Bitrix\Sale\TradingPlatformTable;

/**
 * The order aggregate: reading and writing.
 *
 * Rights are checked first, before anything is loaded. The requested select drives the work:
 * a collection is assembled only when it is asked for, and the external identifiers of the
 * reference books are resolved by id instead of the seven full listings V1 ran on every call.
 *
 * The raw aggregate always carries every key of the requested select, `null` included: the
 * mapper never invents a value for a key it does not find.
 */
final class OrderService
{
	private const CURSOR_FIELD = 'id';

	private const ADD_GROUP = 'add';

	private const UPDATE_GROUP = 'update';

	/** Reference paths behind the pseudo fields, as in V1 (Rest\Entity\Order::getRewritedFields). */
	private const REFERENCE_PATHS = [
		'personTypeXmlId' => 'PERSON_TYPE.XML_ID',
		'statusXmlId' => 'STATUS_TABLE.XML_ID',
	];

	/**
	 * Computed amounts of a basket item: property => the BasketItem getter V1 reads it from.
	 *
	 * The values are names of methods of {@see BasketItem}, called by name, so they move only when
	 * the domain moves them.
	 *
	 * The arithmetic is not duplicated here: rounding precision is a portal option and the number
	 * of roundings depends on the "VAT is inside the price" flag, so an amount computed outside
	 * the domain would differ from V1 on some portals.
	 */
	private const BASKET_ITEM_AMOUNTS = [
		'priceWithVat' => 'getPriceWithVat',
		'basePriceWithVat' => 'getBasePriceWithVat',
		'vatAmount' => 'getVat',
		'finalPrice' => 'getFinalPrice',
		'initialPrice' => 'getInitialPrice',
	];

	/**
	 * Values of a basket item computed by the domain: property => {@see BasketItem} getter.
	 * The getters are not reimplemented: the reserve getter answers zero for a bundle parent and a service.
	 */
	private const BASKET_ITEM_COMPUTED = [
		'withoutProductProvider' => 'isCustom',
		'reservedQuantity' => 'getReservedQuantity',
	];

	/** Computed values counted only when the select names them: the reserve costs a query per position. */
	private const BASKET_ITEM_COMPUTED_ON_DEMAND = ['reservedQuantity'];

	/** Nested part of the aggregate: property => element DTO class. */
	private const COLLECTIONS = [
		'basketItems' => BasketItemDto::class,
		'propertyValues' => PropertyValueDto::class,
		'payments' => PaymentDto::class,
		'shipments' => ShipmentDto::class,
		'tradeBindings' => TradeBindingDto::class,
		'clients' => ClientDto::class,
		'requisiteLink' => RequisiteLinkDto::class,
	];

	/** @var array<class-string, array<string, string>> data type => column map, see getColumnMapOf() */
	private array $columnMaps = [];

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
	}

	public function getOrder(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensureOrderRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$order = $this->getOrderClassName()::load($id);
		if (!$order instanceof Order)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::ok(['item' => $this->buildAggregate($order, $this->getFields($select))]);
	}

	public function getOrderList(
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

		$fields = $this->getFields($select);
		$properties = $this->resolveFlatProperties($fields);
		if ($properties instanceof Result)
		{
			return $properties;
		}

		$legacyFilter = $this->buildLegacyFilter($filter);
		if ($legacyFilter instanceof Result)
		{
			return $legacyFilter;
		}

		// The filter is one conjunction - buildLegacyFilter() refuses OR and NOT - so an empty `in` list
		// leaves it unsatisfiable, and the old ORM has no way to carry one. The shortcut follows every
		// check of the call and has to go once OR groups are supported.
		if (FilterStructureSupport::hasEmptyInCondition($filter))
		{
			return ServiceResult::ok(['items' => []]);
		}

		$rows = $this->fetchRows(
			$properties,
			$legacyFilter,
			LegacyQueryConverter::orderToLegacy($order, $this->getQueryColumnMap()) ?: ['ID' => 'ASC'],
			PaginationSupport::getLimit($pagination),
			PaginationSupport::getOffset($pagination),
		);

		return ServiceResult::ok(['items' => $this->projectRows($rows, $properties)]);
	}

	public function getOrderTail(
		?SelectStructure $select = null,
		?FilterStructure $filter = null,
		?CursorStructure $cursor = null,
	): Result
	{
		$access = $this->accessSupport->ensureOrderRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if ($cursor !== null && $cursor->getField() !== self::CURSOR_FIELD)
		{
			return ServiceResult::validation(
				new Error('Only the "' . self::CURSOR_FIELD . '" cursor field is supported'),
			);
		}

		if (!TailSupport::isLimitAcceptable($cursor))
		{
			return ServiceResult::validation(new Error('The cursor limit must be a positive number'));
		}

		$fields = $this->getFields($select);
		$properties = $this->resolveFlatProperties($fields);
		if ($properties instanceof Result)
		{
			return $properties;
		}

		$legacyFilter = $this->buildLegacyFilter($filter);
		if ($legacyFilter instanceof Result)
		{
			return $legacyFilter;
		}

		// The shortcut of getOrderList(), after the checks of the cursor.
		if (FilterStructureSupport::hasEmptyInCondition($filter))
		{
			return ServiceResult::ok(['items' => [], 'hasMore' => false, 'cursorValue' => null]);
		}

		$idColumn = $this->getColumnMap()[self::CURSOR_FIELD];
		$cursorFilter = TailSupport::legacyFilter($cursor, $idColumn);
		if ($cursorFilter !== [])
		{
			$legacyFilter = $legacyFilter === []
				? $cursorFilter
				: ['LOGIC' => 'AND', $legacyFilter, $cursorFilter];
		}

		$rows = $this->fetchRows(
			array_values(array_unique([...$properties, self::CURSOR_FIELD])),
			$legacyFilter,
			TailSupport::legacyOrder($cursor, $idColumn),
			TailSupport::getProbeLimit($cursor),
			0,
		);

		$page = TailSupport::cutProbe($rows, TailSupport::getLimit($cursor));
		$lastRow = $page['items'] === [] ? null : $page['items'][count($page['items']) - 1];

		return ServiceResult::ok([
			'items' => $this->projectRows($page['items'], $properties),
			'hasMore' => $page['hasMore'],
			'cursorValue' => $lastRow === null ? null : ($lastRow[$idColumn] ?? null),
		]);
	}

	/**
	 * @param bool $newPositionsAreCatalogProducts every position created by the write names a product
	 *        of the catalogue ({@see OrderFormBuilder::build()})
	 */
	public function addOrder(OrderDto $dto, bool $newPositionsAreCatalogProducts = false): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$input = $this->validateWriteInput($dto);
		if (!$input->isSuccess())
		{
			return $input;
		}

		$saved = $this->buildAndSave(
			$this->buildAddForm($dto, $newPositionsAreCatalogProducts),
			$dto,
			isExistingOrder: false,
		);

		return $saved->isSuccess()
			? $this->getOrder((int)$saved->getData()['id'])
			: $saved;
	}

	/**
	 * @param bool $newPositionsAreCatalogProducts every position created by the write names a product
	 *        of the catalogue ({@see OrderFormBuilder::build()})
	 */
	public function updateOrder(int $id, OrderDto $dto, bool $newPositionsAreCatalogProducts = false): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$order = $this->getOrderClassName()::load($id);
		if (!$order instanceof Order)
		{
			return ServiceResult::notFound($id);
		}

		$input = $this->validateWriteInput($dto, $order);
		if (!$input->isSuccess())
		{
			return $input;
		}

		$saved = $this->buildAndSave(
			$this->buildUpdateForm($dto, $order, $newPositionsAreCatalogProducts),
			$dto,
			isExistingOrder: true,
		);

		return $saved->isSuccess() ? $this->getOrder($id) : $saved;
	}

	/**
	 * The whole write pipeline without the save: what V1 offered as `tryadd`, `tryupdate` and
	 * `trymodify` is one operation here, and the identifier is what tells a checked creation from a
	 * checked change. The answer is the assembled order as the domain holds it, the reserves of its
	 * positions included, which the domain moves while assembling it: the order is not saved, so it
	 * is not read back from the database.
	 *
	 * The assembly is not free: the domain pipeline recalculates the basket and asks the product
	 * provider, exactly as it does on a real write. Some of it does not even wait for a save: a
	 * return through the pay system asks the pay system for the money, and a payment or a shipment
	 * the input leaves out is deleted with what stands around it. A checked change refuses both
	 * before anything is assembled, together with the other errors of the input
	 * ({@see PreflightCheckSupport}); the change itself carries them out as before.
	 */
	public function validateOrder(?int $id, OrderDto $dto): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$order = null;
		if ($id !== null)
		{
			$order = $this->getOrderClassName()::load($id);
			if (!$order instanceof Order)
			{
				return ServiceResult::notFound($id);
			}
		}

		$input = $this->validateWriteInput($dto, $order);
		$errors = $order === null ? [] : PreflightCheckSupport::validate($dto, $order);
		if (!$input->isSuccess() || $errors !== [])
		{
			return ServiceResult::validation([...$input->getErrors(), ...$errors]);
		}

		$form = $order === null ? $this->buildAddForm($dto) : $this->buildUpdateForm($dto, $order);

		$built = $this->buildOrder($form, $dto, isExistingOrder: $order !== null);
		if (!$built->isSuccess())
		{
			return $built;
		}

		return ServiceResult::ok(['item' => $this->buildAggregate($built->getData()['order'], [], assembled: true)]);
	}

	public function deleteOrder(int $id): Result
	{
		$access = $this->accessSupport->ensureOrderModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if (!$this->orderExists($id))
		{
			return ServiceResult::notFound($id);
		}

		// The domain path cancels the order with every side effect of a cancellation, suppresses
		// notifications and removes the related entities. V1 saved the already removed object once
		// more afterwards; that extra save is a defect and is not reproduced. The mark the delete
		// handler of REST raises would outlive the call and silence the writes after it, and so would
		// the notifications the domain turns off and turns back on only when the deletion succeeds.
		$deleted = $this->keepNotifyState(
			fn(): Result => SyncEventSuppression::keepMark(fn(): Result => $this->getOrderClassName()::delete($id)),
		);

		return $deleted->isSuccess() ? ServiceResult::ok() : ServiceResult::validation($deleted->getErrors());
	}

	/**
	 * Input checks the data type itself cannot express: the fields an element being created must
	 * carry, the entity an element names being one of this order, the parts owned by the module
	 * `crm` and the CRM entities a client binds, the addressability of an order property value, a
	 * null of a field with no empty value, a quantity of a deducted shipment the domain refuses
	 * without being heard ({@see DeductedShipmentSupport}), and the one combination the pipeline of
	 * `crm` cannot carry out.
	 *
	 * Every write reaches the domain through here — a change, its pre-flight check and the exchange,
	 * which writes by the same two calls — and nothing is built before it answers: the domain prunes
	 * a collection of what the input did not name before it looks at what the input did name.
	 *
	 * A create answers for the person type it names the way an element created inside a collection
	 * answers for its required references. The exchange always names a resolved one: its own code or
	 * the default its settings must hold before an import starts
	 * ({@see \Bitrix\Sale\Infrastructure\Rest\Support\ExternalIdResolver::ensureConfigured()}).
	 *
	 * @param Order|null $order the order being changed; a create has none
	 */
	private function validateWriteInput(OrderDto $dto, ?Order $order = null): Result
	{
		$values = DtoFieldsConverter::getInitializedValues($dto);
		$errors = array_merge(
			CreationFieldsValidator::validate(
				$dto,
				$order === null ? self::ADD_GROUP : self::UPDATE_GROUP,
				rootIsCreatedElement: $order === null,
			),
			ElementLocator::validate($dto, $order),
			ExplicitNullSupport::validate($dto, $order),
			DeductedShipmentSupport::validate($dto, $order),
		);

		if (!$this->isCrmInstalled())
		{
			foreach (['clients', 'requisiteLink'] as $property)
			{
				if (array_key_exists($property, $values))
				{
					$errors[] = new Error('Field "' . $property . '" requires the module crm to be installed');
				}
			}
		}

		$errors = array_merge(
			$errors,
			$this->validateRepeatedClients($values),
			$this->validateClientTypes($values),
			$this->validatePrimaryContacts($values),
			$this->validateClientEntities($values, $order),
			$this->validatePropertyValues($values, $order),
			$this->validatePropertiesAgainstTradeBindings($values),
			$this->validateRepeatedTradeBindings($values, $order),
		);

		return $errors === [] ? ServiceResult::ok() : ServiceResult::validation($errors);
	}

	/**
	 * A client is named once. The pipeline of `crm` binds a contact once however often it is named
	 * and keeps the first company, so a repeat used to be taken in silence, or refused as a second
	 * company it is not. The repeat is refused at the entity it names again, like an element named
	 * twice anywhere else ({@see ElementLocator}), and the other checks of the clients pass it over:
	 * its entity is asked about where it is named first. A contact and a company are two clients
	 * even under one identifier.
	 *
	 * @param array<string, mixed> $values
	 * @return list<Error>
	 */
	private function validateRepeatedClients(array $values): array
	{
		if (!array_key_exists('clients', $values) || !$this->isCrmInstalled() || !Loader::includeModule('crm'))
		{
			return [];
		}

		$errors = [];
		foreach ($this->findRepeatedClients($values) as $position => $repeat)
		{
			$elementPath = 'clients.' . $position;
			$entity = ($repeat['entityTypeId'] === \CCrmOwnerType::Company ? 'company ' : 'contact ')
				. $repeat['entityId']
			;
			$errors[] = new Error(
				'Element "' . $elementPath . '" names ' . $entity . ', which element "clients.' . $repeat['first'] . '"'
				. ' names already: an order binds a client once',
				$elementPath . '.entityId',
			);
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<int, array{first: int, entityTypeId: int, entityId: int}> every client the input
	 *         names again, by its position, with the position that names it first
	 */
	private function findRepeatedClients(array $values): array
	{
		$repeated = [];
		$named = [];
		$position = 0;
		foreach ($values['clients'] ?? [] as $client)
		{
			$current = $position++;
			$element = $client instanceof Dto ? DtoFieldsConverter::getInitializedValues($client) : [];
			$entityTypeId = IdentifierSupport::readPositive($element['entityTypeId'] ?? null);
			$entityId = IdentifierSupport::readPositive($element['entityId'] ?? null);
			if (
				$entityId === null
				|| !in_array($entityTypeId, [\CCrmOwnerType::Contact, \CCrmOwnerType::Company], true)
			)
			{
				continue;
			}

			if (isset($named[$entityTypeId][$entityId]))
			{
				$repeated[$current] = [
					'first' => $named[$entityTypeId][$entityId],
					'entityTypeId' => $entityTypeId,
					'entityId' => $entityId,
				];

				continue;
			}

			$named[$entityTypeId][$entityId] = $current;
		}

		return $repeated;
	}

	/**
	 * A client is a contact or a company, and an order has one company at most. The pipeline of
	 * `crm` keeps exactly that and drops everything else without a word
	 * (`Crm\Controller\OrderContactCompany::prepareFields()`) — after the clients the input did not
	 * name have already been removed, so a deal in place of a contact left the order with no client,
	 * and a second company was lost with an answer of 200.
	 *
	 * The input passes the clients in full, so the bindings the order already has are measured by
	 * the same rule: a company the order is bound to and a new one are two companies.
	 *
	 * @param array<string, mixed> $values
	 * @return list<Error>
	 */
	private function validateClientTypes(array $values): array
	{
		if (!array_key_exists('clients', $values) || !$this->isCrmInstalled() || !Loader::includeModule('crm'))
		{
			return [];
		}

		$errors = [];
		$repeated = $this->findRepeatedClients($values);
		$hasCompany = false;
		$index = 0;
		foreach ($values['clients'] ?? [] as $client)
		{
			if (isset($repeated[$index]))
			{
				$index++;

				continue;
			}

			$path = 'clients.' . $index++ . '.entityTypeId';
			$element = $client instanceof Dto ? DtoFieldsConverter::getInitializedValues($client) : [];
			// A client that names no type at all is refused as a creation that names nothing
			// ({@see CreationFieldsValidator}).
			$entityTypeId = IdentifierSupport::readPositive($element['entityTypeId'] ?? null);
			if ($entityTypeId === null || $entityTypeId === \CCrmOwnerType::Contact)
			{
				continue;
			}

			if ($entityTypeId !== \CCrmOwnerType::Company)
			{
				$errors[] = new Error(
					'Field "' . $path . '" names no type of a client: a client is a contact or a company',
					$path,
				);

				continue;
			}

			if ($hasCompany)
			{
				$errors[] = new Error(
					'Field "' . $path . '" names a second company: an order has one company at most',
					$path,
				);
			}
			$hasCompany = true;
		}

		return $errors;
	}

	/**
	 * An order has one primary contact at most. The pipeline of `crm` marks the first contact primary
	 * when the input marks none (`Crm\Controller\OrderContactCompany::prepareFields()`) and leaves two
	 * marked ones as they are; the second one then throws inside the build
	 * (`Crm\Order\ContactCompanyEntity::checkValueBeforeSet()`), and the write answered 500. A company
	 * is made primary by that pipeline itself, and an order has one company at most
	 * ({@see validateClientTypes()}).
	 *
	 * @param array<string, mixed> $values
	 * @return list<Error>
	 */
	private function validatePrimaryContacts(array $values): array
	{
		if (!array_key_exists('clients', $values) || !$this->isCrmInstalled() || !Loader::includeModule('crm'))
		{
			return [];
		}

		$errors = [];
		$repeated = $this->findRepeatedClients($values);
		$hasPrimaryContact = false;
		$index = 0;
		foreach ($values['clients'] ?? [] as $client)
		{
			if (isset($repeated[$index]))
			{
				$index++;

				continue;
			}

			$path = 'clients.' . $index++ . '.isPrimary';
			$element = $client instanceof Dto ? DtoFieldsConverter::getInitializedValues($client) : [];
			if (
				IdentifierSupport::readPositive($element['entityTypeId'] ?? null) !== \CCrmOwnerType::Contact
				|| ($element['isPrimary'] ?? null) !== true
			)
			{
				continue;
			}

			if ($hasPrimaryContact)
			{
				$errors[] = new Error(
					'Field "' . $path . '" names a second primary contact: an order has one primary contact at most',
					$path,
				);
			}
			$hasPrimaryContact = true;
		}

		return $errors;
	}

	/**
	 * A client binds the order to a CRM entity, and a creation then fills the order properties from
	 * that entity: the pipeline of `crm` reads the card with no regard to the rights of the user
	 * (`Crm\Order\Matcher\FieldMatcher`), and the answer carries what it read. So the entity a client
	 * names has to exist and be readable by the user, and this is asked before anything is built.
	 *
	 * Only a binding the order does not have yet is asked about. A change passes the clients in full
	 * every time, because the identifier of a binding is read-only ({@see ClientDto}); asking about
	 * the bindings already there would lock the user out of every order bound to a card they cannot
	 * read, while those bindings give them nothing new.
	 *
	 * An entity that does not exist and one the user may not read are refused by one and the same
	 * error: an answer telling them apart would tell whether the entity exists. An entity of a type
	 * no client may have is not asked about at all: it is refused for its type
	 * ({@see validateClientTypes()}).
	 *
	 * @param array<string, mixed> $values
	 * @return list<Error>
	 */
	private function validateClientEntities(array $values, ?Order $order): array
	{
		if (!array_key_exists('clients', $values) || !$this->isCrmInstalled() || !Loader::includeModule('crm'))
		{
			return [];
		}

		$bound = [];
		if ($order instanceof \Bitrix\Crm\Order\Order)
		{
			foreach ($order->getContactCompanyCollection() as $client)
			{
				$bound[(int)$client->getField('ENTITY_TYPE_ID')][(int)$client->getField('ENTITY_ID')] = true;
			}
		}

		$repeated = $this->findRepeatedClients($values);
		$paths = [];
		$index = 0;
		foreach ($values['clients'] ?? [] as $client)
		{
			if (isset($repeated[$index]))
			{
				$index++;

				continue;
			}

			$path = 'clients.' . $index++ . '.entityId';
			$element = $client instanceof Dto ? DtoFieldsConverter::getInitializedValues($client) : [];
			// A client that names no entity at all is refused as a creation that names nothing
			// ({@see CreationFieldsValidator}).
			$entityTypeId = IdentifierSupport::readPositive($element['entityTypeId'] ?? null);
			$entityId = IdentifierSupport::readPositive($element['entityId'] ?? null);
			if (
				$entityTypeId === null
				|| $entityId === null
				|| !in_array($entityTypeId, [\CCrmOwnerType::Contact, \CCrmOwnerType::Company], true)
				|| isset($bound[$entityTypeId][$entityId])
			)
			{
				continue;
			}

			$paths[$entityTypeId][$entityId][] = $path;
		}

		$errors = [];
		foreach ($paths as $entityTypeId => $entities)
		{
			$readable = $this->loadReadableCrmEntityIds($entityTypeId, array_keys($entities));
			foreach ($entities as $entityId => $entityPaths)
			{
				if (in_array($entityId, $readable, true))
				{
					continue;
				}

				foreach ($entityPaths as $path)
				{
					$errors[] = new Error('Field "' . $path . '" names no CRM entity available to the user', $path);
				}
			}
		}

		return $errors;
	}

	/**
	 * The entities are looked up by the data table of their type, with no rights applied, so that a
	 * missing entity is told from a present one by this layer alone; the right is then asked of
	 * `crm` for each entity that exists.
	 *
	 * @param list<int> $ids
	 * @return list<int> the identifiers of existing entities the user may read
	 */
	private function loadReadableCrmEntityIds(int $entityTypeId, array $ids): array
	{
		$container = \Bitrix\Crm\Service\Container::getInstance();
		$factory = \CCrmOwnerType::isCorrectEntityTypeId($entityTypeId)
			? $container->getFactory($entityTypeId)
			: null;
		if ($factory === null)
		{
			return [];
		}

		$rows = $factory->getDataClass()::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => $ids],
		])->fetchAll();

		$existing = array_map('intval', array_column($rows, 'ID'));
		$permissions = $container->getUserPermissions()->item();
		// One reading of the permission attributes for every entity instead of one per entity:
		// `canRead()` takes them, the category included, from the cache this fills.
		$permissions->preloadPermissionAttributes($entityTypeId, $existing);

		$readable = [];
		foreach ($existing as $id)
		{
			if ($permissions->canRead($entityTypeId, $id))
			{
				$readable[] = $id;
			}
		}

		return $readable;
	}

	/**
	 * An order property value is addressed by its property, never by its own identifier: the domain
	 * collection is filled with "property id => value" pairs. A pair no property of the order
	 * answers is dropped in silence - and by then the pairs that were passed have already removed
	 * every value they did not name, so a single mistyped identifier clears the order. A property
	 * named twice collapses into one pair just as silently, the last value winning, so it is refused
	 * by its path like an element named twice anywhere else ({@see ElementLocator}). Whether the
	 * order takes a property of its person type is measured on the assembled order
	 * ({@see validatePropertiesTakenByTheOrder()}).
	 *
	 * @param array<string, mixed> $values
	 * @return list<Error>
	 */
	private function validatePropertyValues(array $values, ?Order $order): array
	{
		$errors = [];
		$paths = [];
		$named = [];
		foreach ($values['propertyValues'] ?? [] as $index => $propertyValue)
		{
			$element = DtoFieldsConverter::getInitializedValues($propertyValue);
			// A value that names no property at all is refused as a creation that names nothing
			// ({@see CreationFieldsValidator}); here only a property that exists is looked up.
			$propertyId = IdentifierSupport::readPositive($element['orderPropsId'] ?? null);
			if ($propertyId === null)
			{
				continue;
			}

			$path = 'propertyValues.' . $index;
			if (isset($named[$propertyId]))
			{
				$errors[] = new Error(
					'Element "' . $path . '" names property ' . $propertyId . ', which element "'
					. $named[$propertyId] . '" names already: a property of the order takes one value',
					$path . '.orderPropsId',
				);

				continue;
			}

			$named[$propertyId] = $path;
			$paths[$propertyId][] = '[propertyValues][' . $index . ']';
		}

		if ($paths === [])
		{
			return $errors;
		}

		// The person type of an existing order is fixed and read from the database; a creation
		// carries it in the payload, where the platform already made it required.
		$personTypeId = $order === null
			? (int)($values['personTypeId'] ?? 0)
			: (int)$order->getPersonTypeId();
		if ($personTypeId <= 0)
		{
			return $errors;
		}

		$known = $this->loadPropertyIds(array_keys($paths), $personTypeId);
		foreach ($paths as $propertyId => $elementPaths)
		{
			if (in_array($propertyId, $known, true))
			{
				continue;
			}

			foreach ($elementPaths as $path)
			{
				$errors[] = new Error(
					$path . ' - orderPropsId "' . $propertyId . '" is no property of the order person type',
				);
			}
		}

		return $errors;
	}

	/**
	 * The pipeline of `crm` sets the order properties before it binds the trading platforms, and a
	 * binding that is given a platform makes the order reload its property collection from the
	 * database (`Order::onTradeBindingCollectionModify`). Everything the properties phase put in
	 * memory is dropped, and the answer is still a success.
	 *
	 * Only a binding being created reaches that: an existing one is passed its own platform back,
	 * and the entity ignores a set that changes no value. So the refusal is narrow - the two
	 * collections in one call, a new binding among them, and only where `crm` is installed.
	 *
	 * @param array<string, mixed> $values
	 * @return list<Error>
	 */
	private function validatePropertiesAgainstTradeBindings(array $values): array
	{
		if (!$this->isCrmInstalled() || !array_key_exists('propertyValues', $values))
		{
			return [];
		}

		foreach ($values['tradeBindings'] ?? [] as $binding)
		{
			$element = DtoFieldsConverter::getInitializedValues($binding);
			if ((int)($element['id'] ?? 0) > 0)
			{
				continue;
			}

			return [
				new Error(
					'Fields "propertyValues" and a new element of "tradeBindings" cannot be passed in'
					. ' one call: pass them in separate calls',
				),
			];
		}

		return [];
	}

	/**
	 * An order is bound to a platform under an external number once: the table keeps the pair unique
	 * (`IX_UNIQ_NUMBERS`), and the domain saves the bindings last, after the order, its basket, its
	 * payments and its shipments, so a repeat used to fail the save half way with a database error.
	 *
	 * The number of a new binding is the one the domain will save it under: a number that does not
	 * read as a positive one is replaced with the identifier of the order
	 * (`TradeBindingEntity::save()`). Two such bindings of one platform therefore repeat each other,
	 * also on a create, whose order has no identifier yet. A binding the input names keeps the
	 * platform and the number it was saved with ({@see TradeBindingDto}); one it does not name is
	 * removed before the new ones are saved and repeats nothing. The numbers are compared the way the
	 * database compares them in the index, regardless of the case and of trailing spaces. The repeat is
	 * refused at the new binding, by the number it sent, or by its platform where it sent none.
	 *
	 * @param array<string, mixed> $values
	 * @param Order|null $order the order being changed; a create has none
	 * @return list<Error>
	 */
	private function validateRepeatedTradeBindings(array $values, ?Order $order): array
	{
		$named = [];
		$created = [];
		$position = 0;
		foreach ($values['tradeBindings'] ?? [] as $binding)
		{
			$current = $position++;
			if (!$binding instanceof Dto)
			{
				continue;
			}

			$id = ElementIdentity::existingEntityId($binding);
			if ($id !== null)
			{
				$entity = ElementLocator::find($order, 'tradeBindings', $id);
				if ($entity !== null)
				{
					$key = (int)$entity->getField('TRADING_PLATFORM_ID')
						. ' ' . self::foldTradeBindingNumber((string)$entity->getField('EXTERNAL_ORDER_ID'));
					$named[$key] ??= $current;
				}

				continue;
			}

			$element = DtoFieldsConverter::getInitializedValues($binding);
			$platformId = IdentifierSupport::readPositive($element['tradingPlatformId'] ?? null);
			if ($platformId === null)
			{
				continue;
			}

			$sent = (string)($element['externalOrderId'] ?? '');
			$created[$current] = [
				'platformId' => $platformId,
				'sent' => $sent,
				'number' => (int)$sent > 0 ? $sent : null,
			];
		}

		$errors = [];
		$taken = $named;
		foreach ($created as $current => $binding)
		{
			$number = $binding['number'] ?? ($order === null ? null : (string)$order->getId());
			// A create has no identifier yet: the key stands for the number of the order, whatever it is.
			$key = $binding['platformId'] . ($number === null ? '#' : ' ' . self::foldTradeBindingNumber($number));
			if (!isset($taken[$key]))
			{
				$taken[$key] = $current;

				continue;
			}

			$elementPath = 'tradeBindings.' . $current;
			$under = match (true)
			{
				$binding['number'] !== null => 'number "' . $number . '"',
				$binding['sent'] === '' => 'the number of the order',
				default => 'the number of the order, which is saved in place of "' . $binding['sent'] . '"',
			};
			$errors[] = new Error(
				'Element "' . $elementPath . '" binds the order to trading platform ' . $binding['platformId']
				. ' under ' . $under . ', which element "tradeBindings.' . $taken[$key] . '" binds it under'
				. ' already: an order is bound to a platform under a number once',
				$elementPath . ($binding['sent'] === '' ? '.tradingPlatformId' : '.externalOrderId'),
			);
		}

		return $errors;
	}

	private static function foldTradeBindingNumber(string $number): string
	{
		return mb_strtolower(rtrim($number, ' '));
	}

	/**
	 * @param list<int> $ids
	 * @param int $personTypeId
	 * @return list<int> the identifiers that are properties of this person type
	 */
	private function loadPropertyIds(array $ids, int $personTypeId): array
	{
		$rows = Property::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => $ids, '=PERSON_TYPE_ID' => $personTypeId],
		])->fetchAll();

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildAddForm(OrderDto $dto, bool $newPositionsAreCatalogProducts = false): array
	{
		$form = OrderFormBuilder::build($dto, null, $newPositionsAreCatalogProducts);
		$form['ID'] = 0;

		return $form;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildUpdateForm(OrderDto $dto, Order $order, bool $newPositionsAreCatalogProducts = false): array
	{
		EditableFieldsFilter::dropFieldsNotEditableIn($dto, self::UPDATE_GROUP);

		$form = OrderFormBuilder::build($dto, $order, $newPositionsAreCatalogProducts);
		$form['ID'] = (int)$order->getId();
		// The person type is fixed at creation, and leaving it out of the form is not neutral: the
		// domain then replaces it with the first person type of the site.
		$form['PERSON_TYPE_ID'] = (int)$order->getPersonTypeId();

		return $form;
	}

	/**
	 * The domain pipeline up to the save and no further, so that the pre-flight check and the write
	 * assemble the order by one and the same route.
	 *
	 * It is public for one more reader: the resource of a single basket position writes through the
	 * very same assembly ({@see BasketItemService}), and it needs the assembled order in hand -
	 * the catalog rights of `crm` are checked on it, between the assembly and the save, exactly
	 * where V1 checked them.
	 *
	 * @param array<string, mixed> $form
	 * @param OrderDto $dto the input the form was built from: its paths address the refusals
	 * @param bool $deleteBasketItemsIfNotExists whether the basket loses the positions the form
	 *        does not name; see {@see createBuilderSettings()}
	 * @return Result data: `order` is the assembled, not yet saved order
	 */
	public function buildOrder(
		array $form,
		OrderDto $dto,
		bool $isExistingOrder,
		bool $deleteBasketItemsIfNotExists = true,
	): Result
	{
		$builder = $this->createBuilder(
			$this->createBuilderSettings($form, $isExistingOrder, $deleteBasketItemsIfNotExists),
		);

		try
		{
			$builder->build(['ORDER' => $form]);
		}
		catch (BuildingException)
		{
			// The builder has already collected what went wrong; an empty collection still means
			// the order was not assembled.
		}

		$errors = $builder->getErrorsContainer()->getErrors();
		$order = $builder->getOrder();
		if ($errors !== [] || !$order instanceof Order)
		{
			return ServiceResult::validation($errors ?: [new Error('Order was not built')]);
		}

		$errors = $this->validatePropertiesTakenByTheOrder($dto, $order);
		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		return ServiceResult::ok(['order' => $order]);
	}

	/**
	 * A property of the person type is not yet a property of the order: the domain leaves out an
	 * inactive property the order holds no value of, and a property bound to a delivery, a pay
	 * system or a trading platform the order does not have. The pair of such a property is dropped
	 * in silence, just as the pair of no property at all ({@see validatePropertyValues()}).
	 *
	 * The bindings are known only once the order is assembled: a property of the delivery the same
	 * call gives the order joins its collection on the way. So the property collection of the
	 * assembled order is the measure, and it is read before anything is saved.
	 *
	 * @return list<Error>
	 */
	private function validatePropertiesTakenByTheOrder(OrderDto $dto, Order $order): array
	{
		$values = DtoFieldsConverter::getInitializedValues($dto);
		$properties = $order->getPropertyCollection();

		$errors = [];
		foreach ($values['propertyValues'] ?? [] as $index => $propertyValue)
		{
			$element = DtoFieldsConverter::getInitializedValues($propertyValue);
			$propertyId = IdentifierSupport::readPositive($element['orderPropsId'] ?? null);
			if ($propertyId === null || $properties->getItemByOrderPropertyId($propertyId) !== null)
			{
				continue;
			}

			$path = 'propertyValues.' . $index;
			$errors[] = new Error(
				'Element "' . $path . '" names property ' . $propertyId . ', which the order does not take:'
				. ' the property is inactive, or bound to a delivery, a pay system or a trading platform'
				. ' the order does not have',
				$path . '.orderPropsId',
			);
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $form
	 */
	private function buildAndSave(array $form, OrderDto $dto, bool $isExistingOrder): Result
	{
		$built = $this->buildOrder($form, $dto, $isExistingOrder);
		if (!$built->isSuccess())
		{
			return $built;
		}

		return $this->saveBuiltOrder($built->getData()['order']);
	}

	/**
	 * The one save of the layer, and the reason {@see buildOrder()} may hand an assembled order
	 * out: whoever pauses between the assembly and the save still finishes it here.
	 *
	 * @return Result data: `id` of the saved order
	 */
	public function saveBuiltOrder(Order $order): Result
	{
		$saved = $order->save();
		if (!$saved->isSuccess())
		{
			return ServiceResult::validation($saved->getErrors());
		}

		// A successful save answers successfully: its warnings stay warnings.
		return ServiceResult::ok(['id' => (int)$order->getId()]);
	}

	private function createBuilder(SettingsContainer $settings): OrderBuilder
	{
		return $this->isCrmInstalled() && Loader::includeModule('crm')
			? new \Bitrix\Crm\Order\Builder\OrderBuilderRest($settings)
			: new OrderBuilderRest($settings);
	}

	/**
	 * Settings of the write contract, repeating V1: no user is created, no payment or
	 * shipment appears on its own, discounts are not reapplied, and a collection that was passed
	 * loses whatever the client did not list.
	 *
	 * The basket is the one collection whose rule depends on who is writing, and V1 spelled the
	 * difference with the same setting: the order resource is handed the basket whole and drops
	 * what the input did not name, while a resource writing one position at a time carries only
	 * that position and must leave the rest of the basket alone. The default is therefore the rule
	 * of the order and stays untouched - changing it would silently rewrite the contract of
	 * `sale.order.update`.
	 *
	 * @param array<string, mixed> $form
	 */
	private function createBuilderSettings(
		array $form,
		bool $isExistingOrder,
		bool $deleteBasketItemsIfNotExists = true,
	): SettingsContainer
	{
		return new SettingsContainer([
			'deleteClientsIfNotExists' => true,
			'deleteTradeBindingIfNotExists' => true,
			'deleteBasketItemsIfNotExists' => $deleteBasketItemsIfNotExists,
			'deletePaymentIfNotExists' => true,
			'deleteShipmentIfNotExists' => true,
			'deleteShipmentItemIfNotExists' => true,
			'deletePropertyValuesIfNotExists' => true,
			// Reaching the removal step is the only reason these two are ever enabled. For an
			// existing order the domain skips the whole phase while the collection is empty, and
			// an empty collection is exactly the request to clear it. The auto-creation
			// branches sitting behind the same setting are guarded by "the order is new" and stay
			// out of reach.
			'createDefaultPaymentIfNeed' => $isExistingOrder && ($form['PAYMENT'] ?? null) === [],
			'createDefaultShipmentIfNeed' => $isExistingOrder && ($form['SHIPMENT'] ?? null) === [],
			'createUserIfNeed' => SettingsContainer::SET_ANONYMOUS_USER,
			'cacheProductProviderData' => false,
			'acceptableErrorCodes' => [],
		]);
	}

	private function orderExists(int $id): bool
	{
		$row = $this->getOrderClassName()::getList([
			'select' => ['ID'],
			'filter' => ['=ID' => $id],
			'limit' => 1,
		])->fetch();

		return $row !== false && $row !== null;
	}

	private function isCrmInstalled(): bool
	{
		return ModuleManager::isModuleInstalled('crm');
	}

	/**
	 * @param array<int|string, mixed> $fields structured select; an empty list means every field
	 *     but the computed ones, which are filled only when the select names them: see
	 *     {@see computeValue()}
	 * @param bool $assembled the order is assembled and not saved: what it holds lives in memory only
	 * @return array<string, mixed>
	 */
	private function buildAggregate(Order $order, array $fields, bool $assembled = false): array
	{
		$values = $order->getFieldValues();
		$item = [];
		foreach ($this->getColumnMap() as $property => $column)
		{
			if ($this->isSelected($property, $fields))
			{
				$item[$property] = $values[$column] ?? null;
			}
		}

		if ($this->isSelected('personTypeXmlId', $fields))
		{
			$personTypeId = (int)$order->getPersonTypeId();
			$item['personTypeXmlId'] = $this->loadPersonTypeXmlIds([$personTypeId])[$personTypeId] ?? null;
		}

		if ($this->isSelected('statusXmlId', $fields))
		{
			$statusId = (string)$order->getField('STATUS_ID');
			$item['statusXmlId'] = $this->loadOrderStatusXmlIds([$statusId])[$statusId] ?? null;
		}

		foreach ($this->getComputedProperties() as $property)
		{
			if (!$this->isNamedInSelect($property, $fields))
			{
				continue;
			}

			$item[$property] = $this->computeValue($order, $property);
		}

		foreach (self::COLLECTIONS as $property => $elementClass)
		{
			if (!$this->isSelected($property, $fields))
			{
				continue;
			}

			$item[$property] = $this->buildCollection(
				$order,
				$property,
				$this->getChildFields($property, $fields),
				$assembled,
			);
		}

		return $item;
	}

	/**
	 * A computed property has no column of its own: its value comes from a domain getter.
	 *
	 * The getter runs only for a property the select named, and the default select names nothing:
	 * unlike a column, a computed property stays out of the answer until it is asked for by name.
	 * The getters are not free, and a read that was given no select must not pay for a value nobody
	 * requested. That price is what makes the rule: the VAT getters recount the order over its
	 * shipments, and every taxed shipment costs two reads of the rate dictionary.
	 *
	 * The same rule is why `add`, `update` and `validate` carry no computed property at all: they
	 * answer with the whole order and take no select to name one in.
	 *
	 * The three VAT getters recount the order over its basket when the value is not filled in yet.
	 * The recount changes the loaded object and nothing else: the order is never saved on a read,
	 * and the object is dropped once the response is mapped.
	 */
	private function computeValue(Order $order, string $property): mixed
	{
		return match ($property)
		{
			'vatRate' => $order->getVatRate(),
			'vatSum' => $order->getVatSum(),
			'useVat' => $order->isUsedVat(),
			// The shipment collection, as in sale.order.isshipped, and not the DEDUCTED column
			// behind the `deducted` field.
			'shipped' => $order->isShipped(),
			// The names come from the map of the mapper, so this branch is reachable only when a
			// property was declared there and left without a getter here. Answering null would put
			// an empty field in the response and keep the mistake quiet.
			default => throw new LogicException(
				new \Exception("Computed property {$property} of the order has no getter."),
			),
		};
	}

	/**
	 * @param array<int|string, mixed> $childFields
	 * @return list<array<string, mixed>>|array<string, mixed>|null
	 */
	private function buildCollection(Order $order, string $property, array $childFields, bool $assembled): array|null
	{
		return match ($property)
		{
			'basketItems' => $this->buildBasketItems($order, $childFields, $assembled),
			'propertyValues' => $this->buildPropertyValues($order, $childFields),
			'payments' => $this->buildPayments($order, $childFields),
			'shipments' => $this->buildShipments($order, $childFields),
			'tradeBindings' => $this->buildTradeBindings($order, $childFields),
			'clients' => $this->buildClients($order, $childFields),
			'requisiteLink' => $this->buildRequisiteLink($order, $childFields),
			default => null,
		};
	}

	/**
	 * The basket of an order as the layer projects it, shared with the resource of a single
	 * position ({@see BasketItemService}). It is public for that reason alone: an element read
	 * through the position resource must equal the same element inside the order, field by field,
	 * and a second projection would drift from this one at the first added field.
	 *
	 * The reserves of a saved order are read from the table in one query. An assembled order is
	 * answered with its own reserves: the domain moves them while assembling it, and a new position
	 * has none in the table at all.
	 *
	 * @param array<int|string, mixed> $fields structured select of a position; empty means every field
	 * @param bool $assembled the order is assembled and not saved: what it holds lives in memory only
	 * @param bool $withComputedOnDemand false leaves out {@see BASKET_ITEM_COMPUTED_ON_DEMAND},
	 *        which {@see countBasketItemComputedOnDemand()} then counts for a part of the basket
	 * @return list<array<string, mixed>>
	 */
	public function buildBasketItems(
		Order $order,
		array $fields,
		bool $assembled = false,
		bool $withComputedOnDemand = true,
	): array
	{
		$basket = $order->getBasket();
		if ($basket === null)
		{
			return [];
		}

		$reservations = $this->isSelected('reservations', $fields) && !$assembled
			? $this->loadReservations($this->collectBasketIds($basket))
			: [];

		$computedProperties = array_keys(self::BASKET_ITEM_COMPUTED);
		if (!$withComputedOnDemand || $this->isExpandedPositionSelect($fields))
		{
			$computedProperties = array_values(array_diff($computedProperties, self::BASKET_ITEM_COMPUTED_ON_DEMAND));
		}

		$items = [];
		/** @var BasketItem $basketItem */
		foreach ($basket as $basketItem)
		{
			$item = array_merge(
				$this->project(BasketItemDto::class, $basketItem->getFieldValues(), $fields),
				$this->countBasketItemAmounts($basketItem, $fields),
				$this->countBasketItemComputed($basketItem, $fields, $computedProperties),
			);

			if ($this->isSelected('properties', $fields))
			{
				$item['properties'] = $this->projectAll(
					BasketItemPropertyDto::class,
					$this->collectFieldValues($basketItem->getPropertyCollection()),
					$this->getChildFields('properties', $fields),
				);
			}

			if ($this->isSelected('reservations', $fields))
			{
				$item['reservations'] = $this->projectAll(
					BasketItemReservationDto::class,
					$assembled
						? $this->collectFieldValues($basketItem->getReserveQuantityCollection())
						: $reservations[(int)$basketItem->getId()] ?? [],
					$this->getChildFields('reservations', $fields),
				);
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * The amounts of {@see BASKET_ITEM_AMOUNTS}, computed by the basket item itself through the
	 * getter the map names.
	 *
	 * Only the amounts named by the select are computed. The check is deliberately stricter than
	 * {@see isSelected()}, which reads an empty select as "everything": with an empty select the
	 * response stays the same as before these amounts existed. A select that names the collection
	 * does name them: the platform expands a bare nested name to every field the element type
	 * declares.
	 *
	 * It is also the only gate of the select these amounts pass: the mapper projects whatever was
	 * counted here, see {@see AbstractOrderMapper::getComputedFieldMap()}.
	 *
	 * @param array<int|string, mixed> $fields
	 * @return array<string, float>
	 */
	private function countBasketItemAmounts(BasketItem $basketItem, array $fields): array
	{
		$amounts = [];
		foreach (self::BASKET_ITEM_AMOUNTS as $property => $getter)
		{
			if ($this->isNamedInSelect($property, $fields))
			{
				$amounts[$property] = (float)$basketItem->{$getter}();
			}
		}

		return $amounts;
	}

	/**
	 * Counted by the position getters, not read from columns: the basket column `RESERVE_QUANTITY` is not
	 * what the domain reads. Values of {@see BASKET_ITEM_COMPUTED_ON_DEMAND} cost a query per position,
	 * so they are counted only when the select names them.
	 *
	 * @param array<int|string, mixed> $fields
	 * @param list<string> $properties the part of {@see BASKET_ITEM_COMPUTED} to count
	 * @return array<string, mixed>
	 */
	private function countBasketItemComputed(BasketItem $basketItem, array $fields, array $properties): array
	{
		$computed = [];
		foreach ($properties as $property)
		{
			$isRequested = in_array($property, self::BASKET_ITEM_COMPUTED_ON_DEMAND, true)
				? $this->isNamedInSelect($property, $fields)
				: $this->isSelected($property, $fields)
			;

			if ($isRequested)
			{
				$computed[$property] = $basketItem->{self::BASKET_ITEM_COMPUTED[$property]}();
			}
		}

		return $computed;
	}

	/**
	 * A bare `basketItems` in the select of an order is expanded by the platform into every scalar field
	 * of a position ({@see SelectStructure::getScalarFieldNames()}). Such a list names
	 * {@see BASKET_ITEM_COMPUTED_ON_DEMAND} without the client having asked for them, so it is no demand.
	 *
	 * @param array<int|string, mixed> $fields
	 */
	private function isExpandedPositionSelect(array $fields): bool
	{
		$named = array_filter($fields, 'is_int', ARRAY_FILTER_USE_KEY);

		return array_diff(SelectStructure::getScalarFieldNames(BasketItemDto::class), $named) === [];
	}

	/**
	 * Counts {@see BASKET_ITEM_COMPUTED_ON_DEMAND} only for the given positions, not the whole basket.
	 *
	 * @param list<array<string, mixed>> $items positions of {@see buildBasketItems()}, each carrying its `id`
	 * @param array<int|string, mixed> $fields
	 * @return list<array<string, mixed>>
	 */
	public function countBasketItemComputedOnDemand(Order $order, array $items, array $fields): array
	{
		$basket = $order->getBasket();
		if ($basket === null || $this->isExpandedPositionSelect($fields))
		{
			return $items;
		}

		foreach ($items as $index => $item)
		{
			$basketItem = $basket->getItemById($item['id'] ?? 0);
			if (!$basketItem instanceof BasketItem)
			{
				continue;
			}

			$items[$index] = array_merge(
				$item,
				$this->countBasketItemComputed($basketItem, $fields, self::BASKET_ITEM_COMPUTED_ON_DEMAND),
			);
		}

		return $items;
	}

	/**
	 * @param iterable<BasketItem> $basket
	 * @return list<int>
	 */
	private function collectBasketIds(iterable $basket): array
	{
		$ids = [];
		foreach ($basket as $basketItem)
		{
			$ids[] = (int)$basketItem->getId();
		}

		return $ids;
	}

	/**
	 * The reserves of the whole basket in one query. The domain reads them through the lazy
	 * collection of a position, which costs a query per position; here the table is read directly,
	 * exactly as the other enrichers of this service read their reference books.
	 *
	 * @param list<int> $basketIds
	 * @return array<int, list<array<string, mixed>>> basket position => its reserves
	 */
	private function loadReservations(array $basketIds): array
	{
		$basketIds = $this->filterIds($basketIds);
		if ($basketIds === [])
		{
			return [];
		}

		$rows = BasketReservationTable::getList([
			'select' => ['ID', 'BASKET_ID', 'QUANTITY', 'DATE_RESERVE', 'DATE_RESERVE_END', 'RESERVED_BY', 'STORE_ID'],
			'filter' => ['@BASKET_ID' => $basketIds],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		$reservations = [];
		foreach ($rows as $row)
		{
			$reservations[(int)$row['BASKET_ID']][] = $row;
		}

		return $reservations;
	}

	/**
	 * @param array<int|string, mixed> $fields
	 * @return list<array<string, mixed>>
	 */
	private function buildPropertyValues(Order $order, array $fields): array
	{
		$rows = [];
		$propertyIds = [];
		/** @var PropertyValue $propertyValue */
		foreach ($order->getPropertyCollection() as $propertyValue)
		{
			$propertyId = (int)$propertyValue->getPropertyId();
			$propertyIds[] = $propertyId;
			$rows[] = $propertyValue->getFieldValues() + ['ORDER_PROPS_ID' => $propertyId];
		}

		$xmlIds = $this->isSelected('orderPropsXmlId', $fields)
			? $this->loadPropertyXmlIds($propertyIds)
			: [];

		foreach ($rows as $index => $row)
		{
			$rows[$index]['ORDER_PROPS_XML_ID'] = $xmlIds[(int)$row['ORDER_PROPS_ID']] ?? null;
		}

		return $this->projectAll(PropertyValueDto::class, $rows, $fields);
	}

	/**
	 * @param array<int|string, mixed> $fields
	 * @return list<array<string, mixed>>
	 */
	private function buildPayments(Order $order, array $fields): array
	{
		$rows = [];
		$paySystemIds = [];
		/** @var Payment $payment */
		foreach ($order->getPaymentCollection() as $payment)
		{
			$paySystemId = (int)$payment->getPaymentSystemId();
			$paySystemIds[] = $paySystemId;
			$rows[] = $payment->getFieldValues() + ['PAY_SYSTEM_ID' => $paySystemId];
		}

		$paySystems = $this->isSelected('paySystemXmlId', $fields) || $this->isSelected('paySystemIsCash', $fields)
			? $this->loadPaySystems($paySystemIds)
			: [];

		foreach ($rows as $index => $row)
		{
			$paySystem = $paySystems[(int)$row['PAY_SYSTEM_ID']] ?? [];
			$rows[$index]['PAY_SYSTEM_XML_ID'] = $paySystem['XML_ID'] ?? null;
			$rows[$index]['PAY_SYSTEM_IS_CASH'] = $paySystem['IS_CASH'] ?? null;
		}

		return $this->projectAll(PaymentDto::class, $rows, $fields);
	}

	/**
	 * @param array<int|string, mixed> $fields
	 * @return list<array<string, mixed>>
	 */
	private function buildShipments(Order $order, array $fields): array
	{
		$shipments = [];
		$deliveryIds = [];
		$statusIds = [];

		/** @var Shipment $shipment */
		foreach ($order->getShipmentCollection() as $shipment)
		{
			// The system shipment is an internal bookkeeping entity, never exposed.
			if ($shipment->isSystem())
			{
				continue;
			}

			$deliveryIds[] = (int)$shipment->getDeliveryId();
			$statusIds[] = (string)$shipment->getField('STATUS_ID');
			$shipments[] = $shipment;
		}

		$deliveryXmlIds = $this->isSelected('deliveryXmlId', $fields)
			? $this->loadDeliveryXmlIds($deliveryIds)
			: [];
		$statusXmlIds = $this->isSelected('statusXmlId', $fields)
			? $this->loadDeliveryStatusXmlIds($statusIds)
			: [];

		$rows = [];
		foreach ($shipments as $shipment)
		{
			$row = $shipment->getFieldValues();
			$row['DELIVERY_XML_ID'] = $deliveryXmlIds[(int)$shipment->getDeliveryId()] ?? null;
			$row['STATUS_XML_ID'] = $statusXmlIds[(string)$shipment->getField('STATUS_ID')] ?? null;

			$item = $this->project(ShipmentDto::class, $row, $fields);
			if ($this->isSelected('shipmentItems', $fields))
			{
				$item['shipmentItems'] = $this->projectAll(
					ShipmentItemDto::class,
					$this->collectFieldValues($shipment->getShipmentItemCollection()),
					$this->getChildFields('shipmentItems', $fields),
				);
			}

			$rows[] = $item;
		}

		return $rows;
	}

	/**
	 * @param array<int|string, mixed> $fields
	 * @return list<array<string, mixed>>
	 */
	private function buildTradeBindings(Order $order, array $fields): array
	{
		$rows = [];
		$platformIds = [];
		/** @var TradeBindingEntity $binding */
		foreach ($order->getTradeBindingCollection() as $binding)
		{
			// The field carries the platform, so getTradePlatform() would read the platform row and
			// build a platform object per binding only to hand back the number already at hand.
			$platformId = (int)$binding->getField('TRADING_PLATFORM_ID');
			$platformIds[] = $platformId;
			$row = $binding->getFieldValues();
			$row['TRADING_PLATFORM_ID'] = $platformId;
			$rows[] = $row;
		}

		$xmlIds = $this->isSelected('tradingPlatformXmlId', $fields)
			? $this->loadTradingPlatformXmlIds($platformIds)
			: [];

		foreach ($rows as $index => $row)
		{
			$rows[$index]['TRADING_PLATFORM_XML_ID'] = $xmlIds[(int)$row['TRADING_PLATFORM_ID']] ?? null;
		}

		return $this->projectAll(TradeBindingDto::class, $rows, $fields);
	}

	/**
	 * @param array<int|string, mixed> $fields
	 * @return list<array<string, mixed>>|null null when the module crm is not installed
	 */
	private function buildClients(Order $order, array $fields): ?array
	{
		if (!method_exists($order, 'getContactCompanyCollection'))
		{
			return null;
		}

		return $this->projectAll(
			ClientDto::class,
			$this->collectFieldValues($order->getContactCompanyCollection()),
			$fields,
		);
	}

	/**
	 * @param array<int|string, mixed> $fields
	 * @return array<string, mixed>|null null when the module crm is not installed
	 */
	private function buildRequisiteLink(Order $order, array $fields): ?array
	{
		if (!method_exists($order, 'getRequisiteLink'))
		{
			return null;
		}

		$link = $order->getRequisiteLink();

		return is_array($link) ? $this->project(RequisiteLinkDto::class, $link, $fields) : null;
	}

	/**
	 * @param iterable<object>|null $collection
	 * @return list<array<string, mixed>>
	 */
	private function collectFieldValues(?iterable $collection): array
	{
		$rows = [];
		foreach ($collection ?? [] as $entity)
		{
			$rows[] = $entity->getFieldValues();
		}

		return $rows;
	}

	/**
	 * @param class-string $dtoClass
	 * @param list<array<string, mixed>> $rows
	 * @param array<int|string, mixed> $fields
	 * @return list<array<string, mixed>>
	 */
	private function projectAll(string $dtoClass, array $rows, array $fields): array
	{
		return array_map(fn(array $row): array => $this->project($dtoClass, $row, $fields), $rows);
	}

	/**
	 * Keeps exactly the requested properties, filling an absent domain column with null so the
	 * response never silently loses a requested key.
	 *
	 * @param class-string $dtoClass
	 * @param array<string, mixed> $row
	 * @param array<int|string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function project(string $dtoClass, array $row, array $fields): array
	{
		$item = [];
		foreach ($this->getColumnMapOf($dtoClass) as $property => $column)
		{
			if ($this->isSelected($property, $fields))
			{
				$item[$property] = $row[$column] ?? null;
			}
		}

		return $item;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param list<string> $properties
	 * @return list<array<string, mixed>>
	 */
	private function projectRows(array $rows, array $properties): array
	{
		$columnMap = $this->getColumnMap();

		$items = [];
		foreach ($rows as $row)
		{
			$item = [];
			foreach ($properties as $property)
			{
				$item[$property] = $row[$columnMap[$property]] ?? null;
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * List and tail carry flat fields only: there are no collections in a listing, as in V1.
	 * A collection asked for explicitly is refused instead of being dropped in silence.
	 *
	 * @param array<int|string, mixed> $fields
	 * @return list<string>|Result
	 */
	private function resolveFlatProperties(array $fields): array|Result
	{
		$columnMap = $this->getColumnMap();
		if ($fields === [])
		{
			return array_keys($columnMap);
		}

		$properties = [];
		foreach ($fields as $key => $value)
		{
			$property = is_int($key) ? (string)$value : (string)$key;
			if (!isset($columnMap[$property]))
			{
				return ServiceResult::validation(
					new Error('Field "' . $property . '" is not available in a listing'),
				);
			}

			$properties[] = $property;
		}

		return $properties;
	}

	/**
	 * @return array<int|string, mixed>|Result
	 */
	private function buildLegacyFilter(?FilterStructure $filter): array|Result
	{
		$unsupported = FilterStructureSupport::findUnsupportedSemantics($filter);
		if ($unsupported !== null)
		{
			return ServiceResult::validation($unsupported);
		}

		return LegacyQueryConverter::convertBoolFilterValuesToYn(
			LegacyQueryConverter::filterToLegacy($filter, $this->getQueryColumnMap()),
			AbstractOrderMapper::forDto(OrderDto::class)->getBooleanColumns(),
		);
	}

	/**
	 * @param list<string> $properties
	 * @param array<int|string, mixed> $filter
	 * @param array<string, string> $order
	 * @return list<array<string, mixed>>
	 */
	private function fetchRows(array $properties, array $filter, array $order, int $limit, int $offset): array
	{
		$columnMap = $this->getColumnMap();
		$select = [];
		foreach ($properties as $property)
		{
			$column = $columnMap[$property];
			// A pseudo field is an alias over a reference column, exactly as in V1.
			$select[$column] = self::REFERENCE_PATHS[$property] ?? $column;
		}

		$iterator = $this->getOrderClassName()::getList([
			'select' => $select,
			'filter' => $filter,
			'order' => $order,
			'limit' => $limit,
			'offset' => $offset,
			'runtime' => $this->getReferenceFields(),
			'count_total' => false,
		]);

		return $iterator->fetchAll();
	}

	/**
	 * @return list<Reference>
	 */
	private function getReferenceFields(): array
	{
		return [
			new Reference('PERSON_TYPE', PersonTypeTable::class, Join::on('this.PERSON_TYPE_ID', 'ref.ID')),
			new Reference('STATUS_TABLE', StatusTable::class, Join::on('this.STATUS_ID', 'ref.ID')),
		];
	}

	/**
	 * @param array<int|string, mixed> $fields
	 */
	private function isSelected(string $property, array $fields): bool
	{
		return $fields === [] || $this->isNamedInSelect($property, $fields);
	}

	/**
	 * The strict half of {@see isSelected()}: an empty select means no property here instead of
	 * every one of them. A property whose value has to be counted is filled only on demand, so the
	 * default select is not a demand for it.
	 *
	 * @param array<int|string, mixed> $fields
	 */
	private function isNamedInSelect(string $property, array $fields): bool
	{
		return in_array($property, $fields, true) || array_key_exists($property, $fields);
	}

	/**
	 * @param array<int|string, mixed> $fields
	 * @return array<int|string, mixed>
	 */
	private function getChildFields(string $property, array $fields): array
	{
		return is_array($fields[$property] ?? null) ? $fields[$property] : [];
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private function getFields(?SelectStructure $select): array
	{
		return $select?->getStructuredList() ?? [];
	}

	/**
	 * @return array<string, string>
	 */
	private function getColumnMap(): array
	{
		return $this->getColumnMapOf(OrderDto::class);
	}

	/**
	 * The column map of a part of the aggregate, built once for it.
	 *
	 * A mapper rebuilds the map on every call, and the map is asked for per row: once for the
	 * position, once more for its properties and once for its reservations. The declaration it is
	 * built from is a constant of the class, so the rows of one answer share the map the first of
	 * them made.
	 *
	 * @param class-string $dtoClass
	 * @return array<string, string>
	 */
	private function getColumnMapOf(string $dtoClass): array
	{
		return $this->columnMaps[$dtoClass] ??= AbstractOrderMapper::forDto($dtoClass)->getColumnMap();
	}

	/**
	 * The properties outside the column map: the listing has no column to select them by, so
	 * they are filled on the get path alone.
	 *
	 * @return list<string>
	 */
	private function getComputedProperties(): array
	{
		return AbstractOrderMapper::forDto(OrderDto::class)->getComputedProperties();
	}

	/**
	 * Filter and order address a pseudo field by its reference path; a plain field by its column.
	 *
	 * @return array<string, string>
	 */
	private function getQueryColumnMap(): array
	{
		return array_merge($this->getColumnMap(), self::REFERENCE_PATHS);
	}

	/**
	 * Runs the operation and puts back the state of the notifications of the module it found: the
	 * state is a static flag of the process, so a call that leaves it off silences the letters of
	 * every later call of a batch.
	 *
	 * @param callable(): Result $operation
	 */
	private function keepNotifyState(callable $operation): Result
	{
		/** @var class-string<\Bitrix\Sale\Notify> $notifyClassName */
		$notifyClassName = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->getNotifyClassName();
		$notifyWasDisabled = $notifyClassName::isNotifyDisabled();

		try
		{
			return $operation();
		}
		finally
		{
			$notifyClassName::setNotifyDisable($notifyWasDisabled);
		}
	}

	/**
	 * @return class-string<Order>
	 */
	private function getOrderClassName(): string
	{
		return Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->getOrderClassName();
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string|null>
	 */
	private function loadPersonTypeXmlIds(array $ids): array
	{
		$ids = $this->filterIds($ids);
		if ($ids === [])
		{
			return [];
		}

		$result = [];
		foreach (PersonType::getList(['select' => ['ID', 'XML_ID'], 'filter' => ['@ID' => $ids]]) as $row)
		{
			$result[(int)$row['ID']] = $row['XML_ID'];
		}

		return $result;
	}

	/**
	 * @param list<string> $ids
	 * @return array<string, string|null>
	 */
	private function loadOrderStatusXmlIds(array $ids): array
	{
		return $this->loadStatusXmlIds(OrderStatus::class, $ids);
	}

	/**
	 * @param list<string> $ids
	 * @return array<string, string|null>
	 */
	private function loadDeliveryStatusXmlIds(array $ids): array
	{
		return $this->loadStatusXmlIds(DeliveryStatus::class, $ids);
	}

	/**
	 * @param class-string<\Bitrix\Sale\StatusBase> $statusClass
	 * @param list<string> $ids
	 * @return array<string, string|null>
	 */
	private function loadStatusXmlIds(string $statusClass, array $ids): array
	{
		$ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
		if ($ids === [])
		{
			return [];
		}

		$result = [];
		foreach ($statusClass::getList(['select' => ['ID', 'XML_ID'], 'filter' => ['@ID' => $ids]]) as $row)
		{
			$result[(string)$row['ID']] = $row['XML_ID'];
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string|null>
	 */
	private function loadPropertyXmlIds(array $ids): array
	{
		$ids = $this->filterIds($ids);
		if ($ids === [])
		{
			return [];
		}

		$result = [];
		foreach (Property::getList(['select' => ['ID', 'XML_ID'], 'filter' => ['@ID' => $ids]])->fetchAll() as $row)
		{
			$result[(int)$row['ID']] = $row['XML_ID'];
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, array{XML_ID: string|null, IS_CASH: string|null}>
	 */
	private function loadPaySystems(array $ids): array
	{
		$ids = $this->filterIds($ids);
		if ($ids === [])
		{
			return [];
		}

		$rows = PaySystem\Manager::getList([
			'select' => ['ID', 'XML_ID', 'IS_CASH'],
			'filter' => ['@ID' => $ids],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['ID']] = ['XML_ID' => $row['XML_ID'], 'IS_CASH' => $row['IS_CASH']];
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string|null>
	 */
	private function loadDeliveryXmlIds(array $ids): array
	{
		$result = [];
		foreach ($this->filterIds($ids) as $id)
		{
			$service = Delivery\Services\Manager::getById($id);
			$result[$id] = is_array($service) ? ($service['XML_ID'] ?? null) : null;
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string|null>
	 */
	private function loadTradingPlatformXmlIds(array $ids): array
	{
		$ids = $this->filterIds($ids);
		if ($ids === [])
		{
			return [];
		}

		$rows = TradingPlatformTable::getList([
			'select' => ['ID', 'XML_ID'],
			'filter' => ['@ID' => $ids],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['ID']] = $row['XML_ID'];
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private function filterIds(array $ids): array
	{
		return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
	}
}
