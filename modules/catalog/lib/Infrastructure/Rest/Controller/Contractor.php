<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller;

use Bitrix\Catalog\Infrastructure\Rest\Dto\ContractorDto;
use Bitrix\Catalog\Internal\Service\Rest\ContractorService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

#[DtoType(ContractorDto::class)]
class Contractor extends AbstractController
{
	public function addAction(array $fields, ContractorService $service): GetResponse
	{
		$data = $this->requireResultData($service->createContractor($fields));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item'] ?? $data));
	}
}
