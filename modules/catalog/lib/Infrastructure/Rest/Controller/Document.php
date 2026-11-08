<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller;

use Bitrix\Catalog\Infrastructure\Rest\Dto\DocumentDto;
use Bitrix\Catalog\Internal\Service\Rest\DocumentService;
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
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(DocumentDto::class)]
class Document extends AbstractController
{
	public function getAction(GetRequest $request, DocumentService $service): GetResponse
	{
		$id = $this->requirePositiveId($request->id);
		$data = $this->requireResultData($service->get($id, $request->select));
		/** @var EntityObject $item */
		$item = $data['item'];

		return new GetResponse($this->mapOrmEntity(DocumentDto::class, $item));
	}

	public function listAction(ListRequest $request, DocumentService $service): ListResponse
	{
		$data = $this->requireResultData($service->list(
			$request->select,
			$request->filter,
			$request->order,
			$request->pagination,
		));

		return new ListResponse(
			$this->mapOrmCollection(DocumentDto::class, $data['items']),
		);
	}

	public function addAction(AddRequest $request, DocumentService $service): GetResponse
	{
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		if (!$dto instanceof DocumentDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$data = $this->requireResultData($service->add($dto));
		/** @var EntityObject $item */
		$item = $data['item'];

		return new GetResponse($this->mapOrmEntity(DocumentDto::class, $item));
	}

	public function updateAction(UpdateRequest $request, DocumentService $service): GetResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$id = $this->requirePositiveId($request->id);
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);
		if (!$dto instanceof DocumentDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$data = $this->requireResultData($service->update($id, $dto));
		/** @var EntityObject $item */
		$item = $data['item'];

		return new GetResponse($this->mapOrmEntity(DocumentDto::class, $item));
	}

	/**
	 * Single id or filter restricted to field `id` (= / in).
	 */
	public function deleteAction(DeleteRequest $request, DocumentService $service): DeleteResponse
	{
		$data = $this->requireResultData($service->delete($this->resolveDeleteIds($request)));

		return new DeleteResponse((bool)($data['result'] ?? true));
	}

	/**
	 * Batch delete by documentIds (Engine URI catalog.document.deletelist).
	 */
	public function deleteListAction(array $documentIds, DocumentService $service): DeleteResponse
	{
		$data = $this->requireResultData($service->delete($this->requireIdList($documentIds)));

		return new DeleteResponse((bool)($data['result'] ?? true));
	}

	/**
	 * Single `id` or batch `documentIds` (batch keeps inventory State gate).
	 */
	public function conductAction(DocumentService $service, ?int $id = null, ?array $documentIds = null): BooleanResponse
	{
		if ($documentIds !== null)
		{
			$data = $this->requireResultData($service->conduct($this->requireIdList($documentIds), true));
		}
		else
		{
			$data = $this->requireResultData($service->conduct([$this->requirePositiveId($id)], false));
		}

		return new BooleanResponse((bool)($data['result'] ?? true));
	}

	/**
	 * Batch conduct (Engine URI catalog.document.conductlist).
	 */
	public function conductListAction(array $documentIds, DocumentService $service): BooleanResponse
	{
		return $this->conductAction($service, null, $documentIds);
	}

	/**
	 * Single `id` or batch `documentIds` (batch keeps inventory State gate).
	 */
	public function cancelAction(DocumentService $service, ?int $id = null, ?array $documentIds = null): BooleanResponse
	{
		if ($documentIds !== null)
		{
			$data = $this->requireResultData($service->cancel($this->requireIdList($documentIds), true));
		}
		else
		{
			$data = $this->requireResultData($service->cancel([$this->requirePositiveId($id)], false));
		}

		return new BooleanResponse((bool)($data['result'] ?? true));
	}

	/**
	 * Batch cancel (Engine URI catalog.document.cancellist).
	 */
	public function cancelListAction(array $documentIds, DocumentService $service): BooleanResponse
	{
		return $this->cancelAction($service, null, $documentIds);
	}

	public function confirmAction(int $id, DocumentService $service): BooleanResponse
	{
		$data = $this->requireResultData($service->confirm($this->requirePositiveId($id)));

		return new BooleanResponse((bool)($data['result'] ?? true));
	}

	public function unconfirmAction(int $id, DocumentService $service): BooleanResponse
	{
		$data = $this->requireResultData($service->unconfirm($this->requirePositiveId($id)));

		return new BooleanResponse((bool)($data['result'] ?? true));
	}
}
