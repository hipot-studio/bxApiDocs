<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Controller\Order;

use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Sale\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemDto;
use Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem\AddRequest;
use Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem\ListRequest;
use Bitrix\Sale\Infrastructure\Rest\Service\BasketItemService;
use Bitrix\Sale\Infrastructure\Rest\Support\FieldShapeSupport;

/**
 * Basket position of an order: sale.order.basketitem.*.
 *
 * The position stays on the module scope `sale`, as in V1, so there is no #[Scope] attribute here,
 * and the URI is the one the platform derives from the namespace of the class. The list of fields
 * of the resource is published by the platform from #[DtoType]; a resource of its own for it would
 * be a second answer to the same question.
 *
 * An empty select means "every field", both to the mapper and to the service.
 */
#[DtoType(BasketItemDto::class)]
final class BasketItem extends AbstractController
{
	public function getAction(GetRequest $request, BasketItemService $service): GetResponse
	{
		$id = $this->requirePositiveId($request->id);

		$data = $this->requireResultData($service->getBasketItem($id, $request->select));

		return new GetResponse(
			$this->getDtoMapper()->mapOne($data['item'], $request->select?->getStructuredList() ?? []),
		);
	}

	public function listAction(ListRequest $request, BasketItemService $service): ListResponse
	{
		$data = $this->requireResultData(
			$service->getBasketItemList($request->select, $request->filter, $request->order, $request->pagination),
		);

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($data['items'], $request->select?->getStructuredList() ?? []),
		);
	}

	/**
	 * The order the position joins is addressed beside the fields, in a request of this resource's
	 * own ({@see AddRequest}).
	 *
	 * Idempotency is declared, not inherited: the platform switches it on by the request type, and
	 * this action carries its own rather than the stock create one.
	 */
	#[Idempotent]
	public function addAction(AddRequest $request, BasketItemService $service): GetResponse
	{
		$dto = $this->requireBasketItemDto($request->fields, RequiredGroup::Add->value);

		$data = $this->requireResultData($service->addBasketItem($request->orderId, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item']));
	}

	public function updateAction(UpdateRequest $request, BasketItemService $service): GetResponse
	{
		$id = $this->requirePositiveId($request->id);
		$dto = $this->requireBasketItemDto($request->fields, RequiredGroup::Update->value);

		$data = $this->requireResultData($service->updateBasketItem($id, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item']));
	}

	public function deleteAction(DeleteRequest $request, BasketItemService $service): DeleteResponse
	{
		// A position is deleted one by one, as an order is: a filter would turn a mistyped
		// condition into a mass removal of positions the caller never named.
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$id = $this->requirePositiveId($request->id);

		$this->requireResultData($service->deleteBasketItem($id));

		return new DeleteResponse(true);
	}

	/**
	 * The shape of the input is answered for before the data type is built from it, on this
	 * resource for the same reason as on the order: the platform turns a collection passed as a
	 * scalar into an empty collection, and an empty collection is a request to clear it.
	 * See {@see FieldShapeSupport}.
	 */
	private function requireBasketItemDto(FieldsStructure $fields, string $group): BasketItemDto
	{
		FieldShapeSupport::ensureShape($fields->getItems(), BasketItemDto::class);

		$dto = $fields->convertToDto($group);
		if (!$dto instanceof BasketItemDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		return $dto;
	}
}
