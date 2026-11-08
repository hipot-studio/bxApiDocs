<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\BankRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\BankRequisiteListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\BankRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\CompanyBankRequisiteListRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\ContactBankRequisiteListRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\DeleteBankRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\GetBankRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\RequisiteBankRequisiteListRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\UpdateBankRequisiteRequest;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Command\Requisite\AddBankRequisiteCommand;
use Bitrix\Crm\V2\Public\Command\Requisite\DeleteBankRequisiteCommand;
use Bitrix\Crm\V2\Public\Command\Requisite\UpdateBankRequisiteCommand;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisite as BankRequisiteEntity;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteCollection;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Requisite\BankRequisiteProvider;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteListContext;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

#[DtoType(BankRequisiteDto::class)]
final class BankRequisite extends AbstractController
{
	private ?BankRequisiteDtoMapper $mapper = null;
	private ?BankRequisiteListRequestMapper $listRequestMapper = null;

	public function addAction(AddRequest $request, EntityType $entityType): GetResponse
	{
		/** @var BankRequisiteDto $dto */
		$dto = $request->fields->convertToDto('add');
		$this->assertRequisiteCategoryAllowed((int)$dto->requisiteId);
		$result
			= (new AddBankRequisiteCommand(
				$entityType,
				$this->getMapper()->mapToEntity($dto),
				$this->userId,
				$this->getMapper()->getModifiedFields($dto),
			))
				->setScope(Scope::Rest)
				->run()
		;

		return new GetResponse($this->mapResultToDto($result));
	}

	public function updateAction(UpdateBankRequisiteRequest $request, EntityType $entityType): GetResponse
	{
		$id = $request->id;
		if ($request->fields->getItems() === [])
		{
			throw new RequestValidationException([new Error('The fields property must not be empty.', 'fields')]);
		}
		$this->assertBankRequisiteCategoryAllowed($id);

		/** @var BankRequisiteDto $dto */
		$dto = $request->fields->convertToDto('update');
		$result
			= (new UpdateBankRequisiteCommand(
				$entityType,
				$this->getMapper()->mapToEntity($dto, $id),
				$this->getMapper()->getModifiedFields($dto),
				$this->userId,
			))
				->setScope(Scope::Rest)
				->run()
		;

		return new GetResponse($this->mapResultToDto($result, $id));
	}

	public function getAction(GetBankRequisiteRequest $request, EntityType $entityType): GetResponse
	{
		$id = $request->id;
		$this->assertBankRequisiteCategoryAllowed($id);
		$result = (new BankRequisiteProvider($this->userId))->getById($entityType, $id);

		return new GetResponse(
			$this->mapResultToDto(
				$result,
				$id,
				$request->select?->getStructuredList() ?? [],
			),
		);
	}

	public function deleteAction(DeleteBankRequisiteRequest $request, EntityType $entityType): DeleteResponse
	{
		$id = $request->id;
		$this->assertBankRequisiteCategoryAllowed($id);

		$result
			= (new DeleteBankRequisiteCommand($entityType, $id, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;
		if (!$result->isSuccess())
		{
			$this->throwResultErrors($result, $id);
		}

		return new DeleteResponse();
	}

	public function listByRequisiteAction(
		RequisiteBankRequisiteListRequest $request,
		EntityType $entityType,
	): ListResponse
	{
		$filter = $this->requireListFilter($request);

		return $this->list(
			$request,
			BankRequisiteListContext::createForRequisite(
				$entityType,
				$this->getListRequestMapper()->extractContextId($filter, 'requisiteId'),
			),
			'requisiteId',
		);
	}

	public function listByContactAction(
		ContactBankRequisiteListRequest $request,
		EntityType $entityType,
	): ListResponse
	{
		$filter = $this->requireListFilter($request);

		return $this->list(
			$request,
			BankRequisiteListContext::createForItem(
				$entityType,
				$this->getListRequestMapper()->extractContextId($filter, 'contactId'),
			),
			'contactId',
		);
	}

	public function listByCompanyAction(
		CompanyBankRequisiteListRequest $request,
		EntityType $entityType,
	): ListResponse
	{
		$filter = $this->requireListFilter($request);

		return $this->list(
			$request,
			BankRequisiteListContext::createForItem(
				$entityType,
				$this->getListRequestMapper()->extractContextId($filter, 'companyId'),
			),
			'companyId',
		);
	}

	private function list(
		ListRequest $request,
		BankRequisiteListContext $context,
		string $contextField,
	): ListResponse
	{
		if ($context->isForItem())
		{
			$this->assertCategoryOwnerAllowed(new ItemId($context->getItemType(), $context->getTargetId()));
		}
		else
		{
			$this->assertRequisiteCategoryAllowed($context->getTargetId());
		}

		$requestMapper = $this->getListRequestMapper();
		$requestMapper->validateSelect($request);
		$result = (new BankRequisiteProvider($this->userId))->getList(
			$context,
			$requestMapper->mapFilter($request->filter, $contextField),
			$requestMapper->mapSort($request),
			$request->pagination === null ? null : $requestMapper->mapPager($request),
		);
		if (!$result->isSuccess())
		{
			$this->throwResultErrors($result, $context->getTargetId());
		}

		$collection = $result->getData()[BankRequisiteProvider::DATA_BANK_REQUISITES] ?? null;
		if (!$collection instanceof BankRequisiteCollection)
		{
			throw new SystemException('The bank requisite provider returned an invalid collection.');
		}

		return new ListResponse(
			$this->getMapper()->mapCollection(
				$collection->getAll(),
				$request->select?->getStructuredList() ?? [],
			),
		);
	}

	private function assertRequisiteCategoryAllowed(int $requisiteId): void
	{
		$result = (new \Bitrix\Crm\V2\Public\Provider\Requisite\RequisiteProvider($this->userId))
			->resolveRequisiteOwner($requisiteId, $this->userId)
		;
		$this->assertResolvedCategoryOwner($result, $requisiteId);
	}

	private function assertBankRequisiteCategoryAllowed(int $bankRequisiteId): void
	{
		$result = (new BankRequisiteProvider($this->userId))
			->resolveBankRequisiteOwner($bankRequisiteId, $this->userId)
		;
		$this->assertResolvedCategoryOwner($result, $bankRequisiteId);
	}

	private function assertResolvedCategoryOwner(Result $result, int $addressedId): void
	{
		$owner = $result->getData()['owner'] ?? null;
		if (!$result->isSuccess() || !$owner instanceof ItemId)
		{
			throw new EntityNotFoundException($addressedId);
		}

		$this->assertCategoryOwnerAllowed($owner);
	}

	private function mapResultToDto(Result $result, ?int $id = null, array $fields = []): BankRequisiteDto
	{
		if (!$result->isSuccess())
		{
			$this->throwResultErrors($result, $id);
		}
		$entity = $result->getData()[BankRequisiteProvider::DATA_BANK_REQUISITE] ?? null;
		if (!$entity instanceof BankRequisiteEntity)
		{
			throw new SystemException('The bank requisite provider returned an invalid entity.');
		}

		/** @var BankRequisiteDto $dto */
		$dto = $this->getMapper()->mapOne($entity, $fields);

		return $dto;
	}

	private function throwResultErrors(Result $result, ?int $id = null): void
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === BankRequisiteError::NOT_FOUND)
			{
				$notFoundId = (int)($error->getCustomData()['id'] ?? $id ?? 0);

				throw new EntityNotFoundException($notFoundId);
			}
		}

		$validationErrors = [];
		foreach ($result->getErrors() as $error)
		{
			$customData = (array)$error->getCustomData();
			$validationErrors[] = new Error(
				$error->getLocalizableMessage() ?? $error->getMessage(),
				$customData['field'] ?? $error->getCode(),
				$customData,
			);
		}

		throw new RequestValidationException($validationErrors);
	}

	private function requireListFilter(ListRequest $request): FilterStructure
	{
		if ($request->filter === null)
		{
			throw new RequestValidationException([new Error('The filter property is required.', 'filter')]);
		}

		return $request->filter;
	}

	private function getMapper(): BankRequisiteDtoMapper
	{
		return $this->mapper ??= new BankRequisiteDtoMapper();
	}

	private function getListRequestMapper(): BankRequisiteListRequestMapper
	{
		return $this->listRequestMapper ??= new BankRequisiteListRequestMapper();
	}
}
