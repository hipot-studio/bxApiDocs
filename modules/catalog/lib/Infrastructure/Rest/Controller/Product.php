<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller;

use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductDto;
use Bitrix\Catalog\Internal\Service\Rest\ProductService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(ProductDto::class)]
class Product extends AbstractController
{
	public function getAction(GetRequest $request, ProductService $service): GetResponse
	{
		$selectFields = $request->select?->getList() ?? [];
		$data = $this->requireResultData($service->get(
			$this->requirePositiveId($request->id),
			$request->select,
		));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item'], $selectFields));
	}

	public function listAction(ListRequest $request, ProductService $service): ListResponse
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

	public function addAction(AddRequest $request, ProductService $service): GetResponse
	{
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		if (!$dto instanceof ProductDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$data = $this->requireResultData($service->add($dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item']));
	}

	public function updateAction(UpdateRequest $request, ProductService $service): GetResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$id = $this->requirePositiveId($request->id);
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);
		if (!$dto instanceof ProductDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$data = $this->requireResultData($service->update($id, $dto));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item']));
	}

	public function deleteAction(DeleteRequest $request, ProductService $service): DeleteResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$data = $this->requireResultData($service->delete($this->requirePositiveId($request->id)));

		return new DeleteResponse((bool)($data['result'] ?? true));
	}

	public function downloadAction(array $fields, ProductService $service): ArrayResponse
	{
		$data = $this->requireResultData($service->download($fields));

		return new ArrayResponse(['file' => $data['file'] ?? null]);
	}
}
