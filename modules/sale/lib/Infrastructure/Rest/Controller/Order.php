<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Controller;

use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\TailRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\TailResponse;
use Bitrix\Rest\V3\Schema\Scope;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Infrastructure\Rest\Request\Order\ImportRequest;
use Bitrix\Sale\Infrastructure\Rest\Request\Order\ValidateRequest;
use Bitrix\Sale\Infrastructure\Rest\Service\OrderImportService;
use Bitrix\Sale\Infrastructure\Rest\Service\OrderService;
use Bitrix\Sale\Infrastructure\Rest\Support\FieldShapeSupport;

/**
 * Order aggregate resource: sale.order.*.
 *
 * The order stays on the module scope `sale`, as in V1, so there is no #[Scope] attribute here.
 */
#[DtoType(OrderDto::class)]
final class Order extends AbstractController
{
	private const TAIL_CURSOR_FIELD = 'id';

	public function getAction(GetRequest $request, OrderService $service): GetResponse
	{
		$id = $this->requireRequestId();
		$select = $this->resolveSelect($request);
		$fields = $this->resolveSelectedFields($select);

		$data = $this->requireResultData($service->getOrder($id, $select));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item'], $fields));
	}

	public function listAction(ListRequest $request, OrderService $service): ListResponse
	{
		$select = $this->resolveSelect($request);
		$fields = $this->resolveSelectedFields($select);

		$data = $this->requireResultData(
			$service->getOrderList($select, $request->filter, $request->order, $request->pagination),
		);

		return new ListResponse($this->getDtoMapper()->mapCollection($data['items'], $fields));
	}

	public function tailAction(TailRequest $request, OrderService $service): TailResponse
	{
		$select = $this->resolveSelect($request);
		$fields = $this->resolveSelectedFields($select);

		$data = $this->requireResultData(
			$service->getOrderTail($select, $request->filter, $request->cursor),
		);

		$cursorValue = $data['cursorValue'] ?? null;

		return new TailResponse(
			items: $this->getDtoMapper()->mapCollection($data['items'], $fields),
			hasMore: (bool)($data['hasMore'] ?? false),
			cursorField: self::TAIL_CURSOR_FIELD,
			cursorValue: $cursorValue === null ? null : (string)$cursorValue,
		);
	}

	public function addAction(AddRequest $request, OrderService $service): GetResponse
	{
		$fields = $this->resolveWriteAnswerFields($request);
		$dto = $this->requireOrderDto($request->fields, RequiredGroup::Add->value);

		$data = $this->requireResultData($service->addOrder($dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item'], $fields));
	}

	public function updateAction(UpdateRequest $request, OrderService $service): GetResponse
	{
		$id = $this->requireRequestId();
		$fields = $this->resolveWriteAnswerFields($request);
		$dto = $this->requireOrderDto($request->fields, RequiredGroup::Update->value);

		$data = $this->requireResultData($service->updateOrder($id, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item'], $fields));
	}

	public function deleteAction(DeleteRequest $request, OrderService $service): DeleteResponse
	{
		// An order is deleted one by one: a filter would turn a mistyped condition into a mass
		// removal of orders the caller never named.
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$id = $this->requireRequestId();

		$this->requireResultData($service->deleteOrder($id));

		return new DeleteResponse(true);
	}

	/**
	 * Pre-flight check: the order is assembled by the domain pipeline and never saved. One operation
	 * replaces the three `try*` of V1; the optional identifier decides what is checked, a creation
	 * without it and a change with it. A checked change refuses what the domain would carry out
	 * while assembling the order: a return through the pay system and the deletion of a payment or a
	 * shipment the input leaves out ({@see OrderService::validateOrder()}). Idempotency is not
	 * declared: an operation that saves no order has no result to replay.
	 */
	public function validateAction(ValidateRequest $request, OrderService $service): GetResponse
	{
		$id = $request->id === null ? null : $this->requireRequestId();
		$group = $id === null ? RequiredGroup::Add->value : RequiredGroup::Update->value;
		$fields = $this->resolveWriteAnswerFields($request);

		$dto = $this->requireOrderDto($request->fields, $group);

		$data = $this->requireResultData($service->validateOrder($id, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item'], $fields));
	}

	/**
	 * Exchange with the accounting system. The order is addressed by the external identifier inside
	 * `fields`, and resolving it decides between a creation and a change, as in V1.
	 *
	 * Idempotency is declared, not inherited: the platform switches it on by the request type, and
	 * this action carries its own request rather than the stock create or update one.
	 */
	#[Idempotent]
	public function importAction(ImportRequest $request, OrderImportService $service): GetResponse
	{
		$fields = $this->resolveWriteAnswerFields($request);

		$data = $this->requireResultData($service->import($request));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item'], $fields));
	}

	/**
	 * The order is addressed by its external identifier and by nothing else: the accounting system
	 * knows no internal one, and a filter would turn a mistyped condition into a mass removal. One
	 * scalar needs no request type of its own; the platform refuses an absent value before the
	 * action runs, and the service refuses an empty one.
	 *
	 * Idempotency is declared, not inherited: the platform switches it on by the request type, and
	 * this action carries no request at all.
	 */
	#[Idempotent]
	public function deleteFromImportAction(string $xmlId, OrderImportService $service): DeleteResponse
	{
		$this->requireResultData($service->deleteFromImport($xmlId));

		return new DeleteResponse(true);
	}

	/**
	 * The shape of the input is answered for before the data type is built from it: the platform
	 * turns a collection passed as a scalar into an empty collection, and an empty collection is
	 * how this surface spells "the collection is now nothing". See {@see FieldShapeSupport}.
	 */
	private function requireOrderDto(FieldsStructure $fields, string $group): OrderDto
	{
		FieldShapeSupport::ensureShape($fields->getItems(), OrderDto::class);

		$dto = $fields->convertToDto($group);
		if (!$dto instanceof OrderDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		return $dto;
	}

	/**
	 * The selection a reading is answered by. An empty one means "every field" to the service and
	 * the mapper, so under a scope naming the fields a token may read it becomes those fields. The
	 * platform puts them in place of an absent and of an empty selection itself
	 * (`Rest\V3\Interaction\Request\Request`); the branch is kept as a safeguard should an empty
	 * selection ever reach the controller. Without such a scope the selection is taken as it came.
	 */
	private function resolveSelect(GetRequest|ListRequest|TailRequest $request): ?SelectStructure
	{
		$scope = $request->getOptions()['scope'] ?? null;
		if (!$scope instanceof Scope || $scope->fields === [] || $this->resolveSelectedFields($request->select) !== [])
		{
			return $request->select;
		}

		return SelectStructure::create($scope->fields, $request->getDtoClass(), $request);
	}

	/**
	 * The fields a write is answered by. A write carries no selection, and the platform bounds its
	 * answer by the first name of every field of the scope only: a scope naming `propertyValues.name`
	 * would give out the whole property values. Under a scope naming fields the answer is therefore
	 * selected by those fields, down to the nested ones, and names the order. The selection is built
	 * before the write, so a scope naming a field the order does not have refuses the write itself.
	 * Without such a scope the answer is the whole order.
	 *
	 * @return array<int|string, mixed>
	 */
	private function resolveWriteAnswerFields(AddRequest|UpdateRequest|ValidateRequest|ImportRequest $request): array
	{
		$scope = $request->getOptions()['scope'] ?? null;
		if (!$scope instanceof Scope || $scope->fields === [])
		{
			return [];
		}

		$fields = $this->resolveSelectedFields(
			SelectStructure::create($scope->fields, $request->getDtoClass(), $request),
		);
		if (!in_array('id', $fields, true))
		{
			$fields[] = 'id';
		}

		return $fields;
	}

	/**
	 * Empty list means "every field": the mapper treats it that way, and so does the service.
	 *
	 * @return array<int|string, mixed>
	 */
	private function resolveSelectedFields(?SelectStructure $select): array
	{
		return $select?->getStructuredList() ?? [];
	}
}
