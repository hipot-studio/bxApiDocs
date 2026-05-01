<?php

namespace Bitrix\Rest\V3\Realisation\Controller\Field;

use Bitrix\Main\Request;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\EntityAlreadyExistsException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Dto\Field\CustomDto;
use Bitrix\Rest\V3\Realisation\Dto\Mapping\CustomMapper;
use CUserTypeEntity;

final class Custom extends AbstractCustom
{
	private CUserTypeEntity $userTypeEntity;
	private CustomMapper $mapper;

	public function __construct(Request $request = null)
	{
		$this->userTypeEntity = new CUserTypeEntity();
		$this->mapper = new CustomMapper();

		return parent::__construct($request);
	}

	public function listAction(ListRequest $request, string $entityId): ListResponse
	{
		$selectedFields = $request->select ? $request->select->getList() : [];
		$customFields = $this->getCustomFieldsByFieldId($entityId, $this->getCurrentUser()->getId(), $this->getResponseLanguage());
		$result = $this->mapper->mapCollection($customFields, $selectedFields);

		return new ListResponse($result);
	}

	public function getAction(GetRequest $request, string $entityId): GetResponse
	{
		$selectedFields = $request->select ? $request->select->getList() : [];
		return $this->getResponseWithOneField($entityId, $request->id, $selectedFields);
	}

	public function addAction(AddRequest $request): GetResponse
	{
		/** @var CustomDto $dto */
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);

		$customFieldsByName = $this->getCustomFieldsByFieldName($dto->entityId, $this->getCurrentUser()->getId(), $this->getResponseLanguage());
		if (isset($customFieldsByName[$dto->name]))
		{
			throw new EntityAlreadyExistsException($dto->name);
		}

		$valuesForAdd = $this->mapper->getValuesForAdd($dto);
		$fieldId = $this->userTypeEntity->Add($valuesForAdd);
		if ($fieldId === false)
		{
			global $APPLICATION;
			throw new InternalException(new SystemException('Cannot add custom field: ' . $APPLICATION->GetException()));
		}
		$this->unsetFieldsByEntityId($dto->entityId);

		return $this->getResponseWithOneField($dto->entityId, $fieldId);
	}

	public function deleteAction(DeleteRequest $request, string $entityId): BooleanResponse
	{
		$customField = $this->getCustomField($entityId, $request->id);
		$this->userTypeEntity->Delete($customField['ID']);

		return new BooleanResponse(true);
	}

	public function updateAction(UpdateRequest $request): GetResponse
	{
		if ($request->id === null)
		{
			throw new RequiredFieldInRequestException('id');
		}
		/** @var CustomDto $dto */
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);

		$valuesForUpdate = $this->mapper->getValuesForUpdate($dto);
		if (empty($valuesForUpdate))
		{
			return $this->getResponseWithOneField($dto->entityId, $request->id);
		}
		$customField = $this->getCustomField($dto->entityId, $request->id);
		$this->userTypeEntity->Update($customField['ID'], $valuesForUpdate);
		$this->unsetFieldsByEntityId($dto->entityId);

		return $this->getResponseWithOneField($dto->entityId, $customField['ID']);
	}

	private function getResponseWithOneField(string $entityId, int $id, array $selectedFields = []): GetResponse
	{
		$customField = $this->getCustomField($entityId, $id);
		$dto = $this->mapper->mapOne($customField, $selectedFields);

		return new GetResponse($dto);
	}

	private function getCustomField(string $entityId, int $id): array
	{
		$customFields = $this->getCustomFieldsByFieldId($entityId, $this->getCurrentUser()->getId(), $this->getResponseLanguage());
		if (!isset($customFields[$id]))
		{
			throw new EntityNotFoundException($id);
		}

		return $customFields[$id];
	}
}