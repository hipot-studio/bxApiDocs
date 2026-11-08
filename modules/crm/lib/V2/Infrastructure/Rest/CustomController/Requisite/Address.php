<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Requisite\RequisiteAddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\Requisite\ListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\Requisite\Mapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite\AddRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite\DeleteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite\ListRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite\UpdateRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Response\Address\CreateResponse;
use Bitrix\Crm\V2\Public\Command\Address\Requisite\AddCommand;
use Bitrix\Crm\V2\Public\Command\Address\Requisite\DeleteCommand;
use Bitrix\Crm\V2\Public\Command\Address\Requisite\UpdateCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Requisite\RequisiteProvider;
use Bitrix\Crm\V2\Public\Provider\Address\RequisiteAddressProvider;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Schema\Scope;

final class Address extends AbstractController
{
	public function addAction(AddRequest $request, EntityType $entityType): CreateResponse
	{
		/** @var RequisiteAddressDto $dto */
		$dto = $request->fields->convertToDto('add');
		$this->assertRequisiteCategoryAllowed($dto->requisiteId);
		$this->runCommand(new AddCommand(
			$dto->requisiteId,
			$dto->typeId,
			$entityType->getId(),
			$this->getMapper()->mapToChanges($dto, $request->fields->getItems()),
			$this->userId,
		));

		return new CreateResponse($this->getMapper()->mapToIdentifierDto($dto->requisiteId, $dto->typeId));
	}

	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$this->assertRequisiteCategoryAllowed($request->requisiteId);
		$selectedFields = $request->select?->getList() ?? [];
		$scopeFields = $this->getScopeFields($request->getOptions());

		try
		{
			$address = $this->getProvider()->get(
				$request->requisiteId,
				$request->typeId,
				$entityType->getId(),
				$this->userId,
			);
		}
		catch (ArgumentException $exception)
		{
			throw $this->createValidationException($exception);
		}

		return new GetResponse(
			$this->getMapper()->mapToDto(
				$address,
				$request->requisiteId,
				$request->typeId,
				$selectedFields,
				$scopeFields,
			),
		);
	}

	public function listAction(ListRequest $request, EntityType $entityType): ListResponse
	{
		$requisiteId = (int)$request->getRequisiteId();
		$this->assertRequisiteCategoryAllowed($requisiteId);
		$items = new DtoCollection(RequisiteAddressDto::class);
		$selectedFields = $request->select?->getList() ?? [];
		$scopeFields = $this->getScopeFields($request->getOptions());

		try
		{
			$addresses = $this->getProvider()->getList($requisiteId, $entityType->getId(), $this->userId);
			$addresses = (new ListRequestMapper())->map($addresses, $requisiteId, $request);
			foreach ($addresses as $addressTypeId => $address)
			{
				$items->add($this->getMapper()->mapToDto(
					$address,
					$requisiteId,
					$addressTypeId,
					$selectedFields,
					$scopeFields,
				));
			}
		}
		catch (ArgumentException $exception)
		{
			throw $this->createValidationException($exception);
		}

		return new ListResponse($items);
	}

	public function updateAction(UpdateRequest $request, EntityType $entityType): UpdateResponse
	{
		$this->assertRequisiteCategoryAllowed($request->requisiteId);
		/** @var RequisiteAddressDto $dto */
		$dto = $request->fields->convertToDto('update');
		$this->runCommand(new UpdateCommand(
			$request->requisiteId,
			$request->typeId,
			$entityType->getId(),
			$this->getMapper()->mapToChanges($dto, $request->fields->getItems()),
			$this->userId,
		));

		return new UpdateResponse();
	}

	public function deleteAction(DeleteRequest $request, EntityType $entityType): DeleteResponse
	{
		$this->assertRequisiteCategoryAllowed($request->requisiteId);
		$this->runCommand(new DeleteCommand(
			$request->requisiteId,
			$request->typeId,
			$entityType->getId(),
			$this->userId,
		));

		return new DeleteResponse();
	}

	private function assertRequisiteCategoryAllowed(int $requisiteId): void
	{
		$result = (new RequisiteProvider($this->userId))->resolveRequisiteOwner($requisiteId, $this->userId);
		$owner = $result->getData()['owner'] ?? null;
		if (!$result->isSuccess() || !$owner instanceof ItemId)
		{
			throw new \Bitrix\Rest\V3\Exception\EntityNotFoundException($requisiteId);
		}

		$this->assertCategoryOwnerAllowed($owner);
	}

	private function getMapper(): Mapper
	{
		return new Mapper();
	}

	private function getProvider(): RequisiteAddressProvider
	{
		return new RequisiteAddressProvider();
	}

	private function getScopeFields(array $options): array
	{
		$scope = $options['scope'] ?? null;

		return $scope instanceof Scope ? $scope->fields : [];
	}

	private function runCommand(CommandInterface $command): void
	{
		try
		{
			/** @var Result $result */
			$result = $command->run();
		}
		catch (ArgumentException $exception)
		{
			throw $this->createValidationException($exception);
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}
	}

	private function createValidationException(ArgumentException $exception): RequestValidationException
	{
		return new RequestValidationException([
			new Error($exception->getMessage(), $exception->getParameter()),
		]);
	}
}
