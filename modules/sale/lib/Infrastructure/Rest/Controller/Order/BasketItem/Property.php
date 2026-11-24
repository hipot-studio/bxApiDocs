<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Controller\Order\BasketItem;

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
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemPropertyDto;
use Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem\Property\AddRequest;
use Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem\Property\ListRequest;
use Bitrix\Sale\Infrastructure\Rest\Service\BasketItemPropertyService;
use Bitrix\Sale\Infrastructure\Rest\Support\FieldShapeSupport;

/**
 * Property of a basket position: sale.order.basketitem.property.*.
 * The resource stays on the module scope `sale`, so there is no #[Scope] attribute here.
 * The field list is published by the platform from #[DtoType]; an empty select means every field.
 */
#[DtoType(BasketItemPropertyDto::class)]
final class Property extends AbstractController
{
	public function getAction(GetRequest $request, BasketItemPropertyService $service): GetResponse
	{
		$id = $this->requirePositiveId($request->id);

		$data = $this->requireResultData($service->getProperty($id, $request->select));

		return new GetResponse(
			$this->getDtoMapper()->mapOne($data['item'], $request->select?->getStructuredList() ?? []),
		);
	}

	public function listAction(ListRequest $request, BasketItemPropertyService $service): ListResponse
	{
		$data = $this->requireResultData(
			$service->getPropertyList($request->select, $request->filter, $request->order, $request->pagination),
		);

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($data['items'], $request->select?->getStructuredList() ?? []),
		);
	}

	/**
	 * Idempotency is declared explicitly: the platform enables it by request type,
	 * and {@see AddRequest} is not the stock create request.
	 */
	#[Idempotent]
	public function addAction(AddRequest $request, BasketItemPropertyService $service): GetResponse
	{
		$dto = $this->requirePropertyDto($request->fields, RequiredGroup::Add->value);

		$data = $this->requireResultData($service->addProperty($request->basketItemId, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item']));
	}

	public function updateAction(UpdateRequest $request, BasketItemPropertyService $service): GetResponse
	{
		$id = $this->requirePositiveId($request->id);
		$dto = $this->requirePropertyDto($request->fields, RequiredGroup::Update->value);

		$data = $this->requireResultData($service->updateProperty($id, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item']));
	}

	public function deleteAction(DeleteRequest $request, BasketItemPropertyService $service): DeleteResponse
	{
		// A property is deleted one by one, as a position and an order are: a filter would turn a
		// mistyped condition into a mass removal of properties the caller never named.
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$id = $this->requirePositiveId($request->id);

		$this->requireResultData($service->deleteProperty($id));

		return new DeleteResponse(true);
	}

	/**
	 * The input shape is checked before the DTO is built: weak mode would turn a `sort` of `1.5` into `1`.
	 */
	private function requirePropertyDto(FieldsStructure $fields, string $group): BasketItemPropertyDto
	{
		FieldShapeSupport::ensureShape($fields->getItems(), BasketItemPropertyDto::class);

		$dto = $fields->convertToDto($group);
		if (!$dto instanceof BasketItemPropertyDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		return $dto;
	}
}
