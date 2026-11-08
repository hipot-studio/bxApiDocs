<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller\Document;

use Bitrix\Catalog\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Catalog\Infrastructure\Rest\Dto\DocumentContractorDto;
use Bitrix\Catalog\Internal\Service\Rest\DocumentContractorService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(DocumentContractorDto::class)]
class Contractor extends AbstractController
{
	public function addAction(AddRequest $request, DocumentContractorService $service): GetResponse
	{
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		if (!$dto instanceof DocumentContractorDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$data = $this->requireResultData($service->add($dto));
		$selectFields = ['id', 'documentId', 'entityTypeId', 'entityId'];

		return new GetResponse(
			$this->getDtoMapper()->mapOne($data['item'] ?? [], $selectFields),
		);
	}

	public function deleteAction(DeleteRequest $request, DocumentContractorService $service): DeleteResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$data = $this->requireResultData($service->delete($this->requirePositiveId($request->id)));

		return new DeleteResponse((bool)($data['result'] ?? true));
	}

	public function listAction(ListRequest $request, DocumentContractorService $service): ListResponse
	{
		$data = $this->requireResultData($service->list(
			$request->select,
			$request->filter,
			$request->order,
			$request->pagination,
		));
		$selectFields = $request->select?->getList() ?? [];

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($data['items'] ?? [], $selectFields),
		);
	}
}
