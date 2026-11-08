<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller\Product;

use Bitrix\Catalog\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductFieldsDto;
use Bitrix\Catalog\Internal\Service\Rest\ProductFieldsService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

#[DtoType(ProductFieldsDto::class)]
class Fields extends AbstractController
{
	public function getByFilterAction(array $filter, ProductFieldsService $service): ArrayResponse
	{
		$data = $this->requireResultData($service->getByFilter($filter));

		return new ArrayResponse($data);
	}
}
