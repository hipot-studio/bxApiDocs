<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller\Document;

use Bitrix\Catalog\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Catalog\Infrastructure\Rest\Dto\UserfieldDocumentDto;
use Bitrix\Catalog\Internal\Service\Rest\UserfieldDocumentService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * Document UF values (list/update). Schema CRUD is not covered here —
 * UF entity ids are per docType (StoreDocumentSpecificTable), not StoreDocumentTable.
 */
#[DtoType(UserfieldDocumentDto::class)]
class Userfield extends AbstractController
{
	public function listAction(ListRequest $request, UserfieldDocumentService $service): ListResponse
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

	public function updateAction(UpdateRequest $request, UserfieldDocumentService $service): GetResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$id = $this->requirePositiveId($request->id);
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);
		if (!$dto instanceof UserfieldDocumentDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$data = $this->requireResultData($service->update($id, $dto));

		return new GetResponse(
			$this->getDtoMapper()->mapOne($data['item'] ?? $data, ['id', 'docType', 'userFields']),
		);
	}
}
