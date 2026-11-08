<?php

namespace Bitrix\Sign\Service\Document;

use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service;
use Bitrix\Sign\Service\Integration\Crm\CompanyRequisiteFieldPolicy;
use Bitrix\Sign\Service\Integration\Crm\FieldSelectorService;
use Bitrix\Sign\Service\Providers\LegalInfoProvider;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\FieldType;

class FieldService
{
	private readonly CompanyRequisiteFieldPolicy $companyRequisiteFieldPolicy;

	public function __construct(
		private readonly Service\Providers\ProfileProvider $profileProvider,
		private readonly Service\Providers\MemberDynamicFieldInfoProvider $memberDynamicFieldProvider,
		private readonly FieldSelectorService $crmFieldSelectorService,
		private readonly FieldAccessService $fieldAccessService,
		?CompanyRequisiteFieldPolicy $companyRequisiteFieldPolicy = null,
	)
	{
		$this->companyRequisiteFieldPolicy = $companyRequisiteFieldPolicy ?? new CompanyRequisiteFieldPolicy();
	}

	public function loadByUserId(int $currentUserId, array $options = []): Result
	{
		$crmFieldsResult = $this->crmFieldSelectorService->getFields($options);
		if (!$crmFieldsResult->isSuccess())
		{
			return $crmFieldsResult;
		}

		$crmFieldsData = $this->filterExcludedFields($crmFieldsResult->getData());
		$presetId = isset($options['presetId']) && is_numeric($options['presetId'])
			? (int)$options['presetId']
			: null
		;
		$areRequisitesHidden = (int)($options['hideRequisites'] ?? 1) !== 0;
		$fieldSetCodes = $areRequisitesHidden
			? []
			: $this->crmFieldSelectorService->getCompanyRequisiteFieldSetFieldCodes($presetId)
		;
		$crmFieldsData['fields'] = $this->companyRequisiteFieldPolicy->filterSelectorTree(
			$crmFieldsData['fields'] ?? [],
			$fieldSetCodes,
		);
		$crmFieldsData['options']['permissions']['userField']['addByCategory'] =
			$this->fieldAccessService->getAddByCategoryPermissions($crmFieldsData, $currentUserId)
		;
		$crmFieldsData['fields'] = array_merge(
			$crmFieldsData['fields'],
			$this->profileProvider->getFieldsForSelector(),
			$this->memberDynamicFieldProvider->getFieldsForSelector(),
		);

		$priorityCategories = $options['priorityCategories'] ?? [];
		if (!empty($priorityCategories))
		{
			$crmFieldsData['fields'] = $this->sortFieldsByPriorityCategories(
				$crmFieldsData['fields'],
				$priorityCategories,
			);
		}

		return (new Result())->setData($crmFieldsData);
	}

	private function filterExcludedFields(array $crmFieldsData): array
	{
		$excludedFields = $this->getExcludedFields();
		foreach ($excludedFields as $category => $fields)
		{
			if (empty($crmFieldsData['fields'][$category]['FIELDS']))
			{
				continue;
			}

			$crmFieldsData['fields'][$category]['FIELDS'] = array_values(
				array_filter(
					$crmFieldsData['fields'][$category]['FIELDS'],
					static fn($field) => !in_array($field['entity_field_name'], $fields, true)
				)
			);
		}

		return $crmFieldsData;
	}

	/**
	 * Single source of the CRM field denylist: also consumed by the placeholder restriction rule.
	 *
	 * @return array<string, list<string>> entity category => entity field names
	 */
	public function getExcludedFields(): array
	{
		return [
			'COMPANY' => [
				'LOGO',
				'ADDRESS',
				'REG_ADDRESS',
				'IS_MY_COMPANY',
				'ORIGIN_VERSION',
				'LAST_ACTIVITY_TIME',
				'LAST_ACTIVITY_BY',
				'LINK',
			],
			'SMART_B2E_DOC' => [
				'XML_ID',
				'STAGE_ID',
				'LAST_ACTIVITY_BY',
				'LAST_ACTIVITY_TIME',
			],
		];
	}

	/**
	 * @param array<string, array> $fields
	 * @param list<string> $priorityCategories
	 * @return array<string, array>
	 */
	private function sortFieldsByPriorityCategories(array $fields, array $priorityCategories): array
	{
		$priorityFields = [];
		foreach ($priorityCategories as $category)
		{
			if (isset($fields[$category]))
			{
				$priorityFields[$category] = $fields[$category];
			}
		}

		return $priorityFields + array_diff_key($fields, $priorityFields);
	}

	public function convertUserFieldType(string $userFieldType): string
	{
		return match ($userFieldType)
		{
			FieldType::SNILS => FieldType::SNILS,
			FieldType::FIRST_NAME => FieldType::FIRST_NAME,
			FieldType::LAST_NAME => FieldType::LAST_NAME,
			FieldType::PATRONYMIC => FieldType::PATRONYMIC,
			FieldType::POSITION => FieldType::POSITION,
			FieldType::DATE, FieldType::DATETIME => FieldType::DATE,
			FieldType::LIST, FieldType::ENUMERATION => FieldType::LIST,
			FieldType::DOUBLE => FieldType::DOUBLE,
			FieldType::INTEGER => FieldType::INTEGER,
			FieldType::ADDRESS => FieldType::ADDRESS,
			default => FieldType::STRING,
		};
	}

	/**
	 * Semantic type of a profile field. The codes whose meaning the user field type does not carry
	 * are declared by their owner, LegalInfoProvider::CONTRACT_FIELD_TYPES, next to the codes the
	 * provider resolves on its own.
	 */
	public function resolveProfileFieldType(string $fieldName, string $userFieldType): string
	{
		return LegalInfoProvider::CONTRACT_FIELD_TYPES[$fieldName]
			?? $this->convertUserFieldType($userFieldType)
		;
	}

	public function convertCrmFieldType(string $crmFieldType): string
	{
		return match ($crmFieldType)
		{
			FieldType::DATE, FieldType::DATETIME => FieldType::DATE,
			FieldType::LIST => FieldType::LIST,
			FieldType::DOUBLE => FieldType::DOUBLE,
			FieldType::INTEGER => FieldType::INTEGER,
			FieldType::ADDRESS => FieldType::ADDRESS,
			default => FieldType::STRING,
		};
	}

	public function getB2eRegionalFieldTypeByBlockCode(string $code): string
	{
		return match ($code)
		{
			BlockCode::B2E_EXTERNAL_ID => FieldType::EXTERNAL_ID,
			BlockCode::B2E_EXTERNAL_DATE_CREATE => FieldType::EXTERNAL_DATE,
			default => '',
		};
	}
}
