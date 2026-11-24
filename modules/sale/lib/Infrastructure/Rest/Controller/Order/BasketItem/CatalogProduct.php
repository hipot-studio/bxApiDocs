<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Controller\Order\BasketItem;

use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Sale\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItem\CatalogProductDto;
use Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem\AddRequest;
use Bitrix\Sale\Infrastructure\Rest\Service\BasketItemService;
use Bitrix\Sale\Infrastructure\Rest\Support\FieldShapeSupport;

/**
 * Basket position holding goods of the catalog: sale.order.basketitem.catalogproduct.*.
 *
 * The resource exists for the writing alone. A position is one entity whichever provider counts
 * it, so it is read, listed and removed by the resource of the position
 * ({@see \Bitrix\Sale\Infrastructure\Rest\Controller\Order\BasketItem}); repeating those actions
 * here would publish a second address for the very same answer.
 *
 * What the address does carry is the provider: the data type of this surface holds no field naming
 * one, so a caller chooses the catalog by coming here and by nothing else. Both actions therefore
 * demand the module `catalog`, and the demand is answered by the service before the order is
 * touched. Writing itself is the write of the position, with the set of fields a catalog product
 * lets its client name ({@see CatalogProductDto}).
 *
 * The scope stays the module one, and the URI is the one the platform derives from the namespace.
 */
#[DtoType(CatalogProductDto::class)]
final class CatalogProduct extends AbstractController
{
	/**
	 * The order the position joins is addressed beside the fields, in the request the resource of
	 * the position declares ({@see AddRequest}): the address of an order is the same question on
	 * both resources, and it has one answer.
	 */
	#[Idempotent]
	public function addAction(AddRequest $request, BasketItemService $service): GetResponse
	{
		$dto = $this->requireCatalogProductDto($request->fields, RequiredGroup::Add->value);

		$data = $this->requireResultData($service->addCatalogProduct($request->orderId, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item']));
	}

	public function updateAction(UpdateRequest $request, BasketItemService $service): GetResponse
	{
		$id = $this->requirePositiveId($request->id);
		$dto = $this->requireCatalogProductDto($request->fields, RequiredGroup::Update->value);

		$data = $this->requireResultData($service->updateCatalogProduct($id, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item']));
	}

	/**
	 * The shape of the input is answered for before the data type is built from it, for the reason
	 * written down at {@see FieldShapeSupport}.
	 */
	private function requireCatalogProductDto(FieldsStructure $fields, string $group): CatalogProductDto
	{
		FieldShapeSupport::ensureShape($fields->getItems(), CatalogProductDto::class);

		$dto = $fields->convertToDto($group);
		if (!$dto instanceof CatalogProductDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		return $dto;
	}
}
