<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller\Product\Property;

use Bitrix\Catalog\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductPropertyTypeDto;
use Bitrix\Catalog\Infrastructure\Rest\Support\EnumListMapper;
use Bitrix\Catalog\Internal\Service\Rest\EnumService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(ProductPropertyTypeDto::class)]
class Type extends AbstractController
{
	public function listAction(ListRequest $request, EnumService $service): ListResponse
	{
		$this->rejectListOptions($request);

		$data = $this->requireResultData($service->getProductPropertyTypes());

		return new ListResponse(
			EnumListMapper::mapFieldsCollection(
				ProductPropertyTypeDto::class,
				$data['items'],
				$request->select?->getList() ?? [],
			),
		);
	}
}
