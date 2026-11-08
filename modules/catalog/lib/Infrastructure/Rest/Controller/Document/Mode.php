<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller\Document;

use Bitrix\Catalog\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Catalog\Infrastructure\Rest\Dto\DocumentModeDto;
use Bitrix\Catalog\Internal\Service\Rest\DocumentModeService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

#[DtoType(DocumentModeDto::class)]
class Mode extends AbstractController
{
	public function statusAction(DocumentModeService $service): ArrayResponse
	{
		$data = $this->requireResultData($service->status());

		return new ArrayResponse([
			'status' => $data['status'] ?? null,
		]);
	}

	public function statusDetailsAction(DocumentModeService $service): ArrayResponse
	{
		return new ArrayResponse($this->requireResultData($service->statusDetails()));
	}
}
