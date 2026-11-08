<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller;

use Bitrix\Catalog\Infrastructure\Rest\Dto\VatDto;
use Bitrix\Catalog\Internal\Service\Rest\VatService;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(VatDto::class)]
class Vat extends AbstractController
{
	public function getAction(GetRequest $request, VatService $service): GetResponse
	{
		$id = $this->requirePositiveId($request->id);
		$data = $this->requireResultData($service->get($id, $request->select));
		/** @var EntityObject $item */
		$item = $data['item'];

		return new GetResponse($this->mapOrmEntity(VatDto::class, $item));
	}

	public function listAction(ListRequest $request, VatService $service): ListResponse
	{
		$data = $this->requireResultData($service->list(
			$request->select,
			$request->filter,
			$request->order,
			$request->pagination,
		));

		return new ListResponse(
			$this->mapOrmCollection(VatDto::class, $data['items']),
		);
	}

	public function addAction(AddRequest $request, VatService $service): GetResponse
	{
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		if (!$dto instanceof VatDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$data = $this->requireResultData($service->add($dto));
		/** @var EntityObject $item */
		$item = $data['item'];

		return new GetResponse($this->mapOrmEntity(VatDto::class, $item));
	}

	public function updateAction(UpdateRequest $request, VatService $service): GetResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$id = $this->requirePositiveId($request->id);
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);
		if (!$dto instanceof VatDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$data = $this->requireResultData($service->update($id, $dto));
		/** @var EntityObject $item */
		$item = $data['item'];

		return new GetResponse($this->mapOrmEntity(VatDto::class, $item));
	}

	public function deleteAction(DeleteRequest $request, VatService $service): DeleteResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$data = $this->requireResultData($service->delete($this->requirePositiveId($request->id)));

		return new DeleteResponse((bool)($data['result'] ?? true));
	}
}
