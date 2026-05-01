<?php

namespace Bitrix\Rest\V3\Realisation\Controller\Field\Custom;

use Bitrix\Main\Request;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Controller\Field\AbstractCustom;
use Bitrix\Rest\V3\Realisation\Dto\Field\Custom\EnumDto;
use Bitrix\Rest\V3\Realisation\Dto\Mapping\EnumMapper;
use Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException;
use Bitrix\Rest\V3\Realisation\Request\Field\Custom\Enum\DeleteRequest;
use Bitrix\Rest\V3\Realisation\Request\Field\Custom\Enum\ListRequest;
use CUserFieldEnum;

final class Enum extends AbstractCustom
{
	private const USER_TYPE_ID = 'enumeration';

	private CUserFieldEnum $userFieldEnum;
	private EnumMapper $mapper;

	public function __construct(Request $request = null)
	{
		$this->userFieldEnum = new CUserFieldEnum();
		$this->mapper = new EnumMapper();

		parent::__construct($request);
	}

	public function listAction(ListRequest $request): ListResponse
	{
		if (!$request->filter)
		{
			throw new RequiredFieldInRequestException('filter');
		}

		$conditions = $request->filter->getSimpleFilterConditions();
		if (!isset($conditions['fieldId']))
		{
			throw new RequiredFieldInRequestException('filter');
		}

		$fieldId = $conditions['fieldId'];
		$enumValues = $this->getEnumFields($request->entityId, $fieldId);
		if (empty($enumValues))
		{
			return new ListResponse(new DtoCollection(EnumDto::class));
		}

		$selectedFields = $request->select ? $request->select->getList() : [];

		$result = $this->mapper->mapCollection($enumValues, $selectedFields);

		return new ListResponse($result);
	}

	public function getAction(GetRequest $request): GetResponse
	{
		$enumValues = $this->getEnumValuesByFilterData(['ID' => $request->id]);
		if (empty($enumValues))
		{
			throw new EntityNotFoundException($request->id);
		}
		$enumData = $enumValues[0];
		$selectedFields = $request->select ? $request->select->getList() : [];
		$enumDto = $this->mapper->mapOne($enumData, $selectedFields);

		return new GetResponse($enumDto);
	}

	public function addAction(AddRequest $request): GetResponse
	{
		/** @var EnumDto $dto */
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);

		$customFields = $this->getCustomFieldsByFieldId($dto->entityId, $this->getCurrentUser()->getId(), $this->getResponseLanguage());
		if (!isset($customFields[$dto->fieldId]))
		{
			throw new FieldNotFoundException($dto->fieldId);
		}
		$customField = $customFields[$dto->fieldId];

		$valuesForAdd = [
			'n0' => $this->mapper->getValuesForAdd($dto)
		];
		$result = $this->userFieldEnum->SetEnumValues($customField['ID'], $valuesForAdd);
		if (!$result)
		{
			global $APPLICATION;
			throw new InternalException(new SystemException('Cannot add enum value: ' . $APPLICATION->GetException()));
		}

		$lastAdded = [];
		$enumList = $this->getEnumFields($dto->entityId, $dto->fieldId);
		foreach ($enumList as $enum)
		{
			if (empty($lastAdded))
			{
				$lastAdded = $enum;
			}

			if ((int) $enum['ID'] > (int) $lastAdded['ID'])
			{
				$lastAdded = $enum;
			}
		}

		return new GetResponse($this->mapper->mapOne($lastAdded));
	}

	public function deleteAction(DeleteRequest $request): BooleanResponse
	{
		if (!$request->id)
		{
			throw new RequiredFieldInRequestException('id');
		}
		$enumData = $this->getEnumValuesByFilterData(['ID' => $request->id]);
		if (empty($enumData))
		{
			throw new EntityNotFoundException($request->id);
		}
		$enumValue = $enumData[0];

		$customFields = $this->getCustomFieldsByFieldId($request->entityId, $this->getCurrentUser()->getId(), $this->getResponseLanguage());
		if (!isset($customFields[$enumValue['USER_FIELD_ID']]))
		{
			throw new FieldNotFoundException($enumValue['USER_FIELD_ID']);
		}
		$valuesForDelete = [
			$request->id => ['DEL' => 'Y']
		];
		$result = $this->userFieldEnum->SetEnumValues($enumValue['USER_FIELD_ID'], $valuesForDelete);
		if (!$result)
		{
			global $APPLICATION;
			throw new InternalException(new SystemException('Cannot delete enum value: ' . $APPLICATION->GetException()));
		}

		return new BooleanResponse(true);
	}


	public function updateAction(UpdateRequest $request): GetResponse
	{
		/** @var EnumDto $dto */
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);
		$updateData = $this->mapper->getValuesForAdd($dto);
		$enumValues = $this->getEnumValuesByFilterData(['ID' => $request->id]);
		if (empty($enumValues))
		{
			throw new EntityNotFoundException($request->id);
		}
		$enumData = $enumValues[0];
		if (empty($updateData))
		{
			return new GetResponse($this->mapper->mapOne($enumData));
		}
		$enumValues = array_merge($enumValues[0], $updateData);
		$valuesForUpdate = [
			$request->id => $enumValues,
		];
		unset($valuesForUpdate[$request->id]['ID']);
		unset($valuesForUpdate[$request->id]['USER_FIELD_ID']);
		$result = $this->userFieldEnum->SetEnumValues($enumData['USER_FIELD_ID'], $valuesForUpdate);
		if (!$result)
		{
			global $APPLICATION;
			throw new InternalException(new SystemException('Cannot update enum value: ' . $APPLICATION->GetException()));
		}

		return new GetResponse($this->mapper->mapOne($enumValues));
	}

	private function getEnumFields(string $entityId, string $fieldId, ?int $enumId = null): array
	{
		$customFields = $this->getCustomFieldsByFieldId($entityId, $this->getCurrentUser()->getId(), $this->getResponseLanguage());
		if (!isset($customFields[$fieldId]))
		{
			throw new FieldNotFoundException($fieldId);
		}

		$customField = $customFields[$fieldId];
		if ($customField['USER_TYPE_ID'] !== self::USER_TYPE_ID)
		{
			throw new FieldNotFoundException($fieldId);
		}

		$filterData = ['USER_FIELD_ID' => $customField['ID']];
		if ($enumId !== null)
		{
			$filterData['ID'] = $enumId;
		}

		return $this->getEnumValuesByFilterData($filterData);
	}

	private function getEnumValuesByFilterData(array $filterData): array
	{
		$enumList = $this->userFieldEnum->GetList([], $filterData);
		$enumValues = [];

		while ($enum = $enumList->Fetch())
		{
			$enumValues[] = $enum;
		}

		return $enumValues;
	}
}