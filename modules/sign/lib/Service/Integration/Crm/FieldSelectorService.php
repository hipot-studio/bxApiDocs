<?php

namespace Bitrix\Sign\Service\Integration\Crm;

use Bitrix\Crm;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Sign\Result\Result;

class FieldSelectorService
{
	private const EMPTY_COMPANY_REQUISITE_CONFIGURATION = [
		'presetFieldCodes' => [],
		'fieldSetCodes' => [],
	];

	public function getFields(array $options = []): Result
	{
		$result = new Result();
		if (!Loader::includeModule('crm'))
		{
			return $result->addError(new Error('Module `crm` is not installed'));
		}

		$selector = new Crm\Controller\Form\Fields\Selector();
		$crmFieldsData = $selector->getDataAction($options);
		$result->setData($crmFieldsData);

		return $result;
	}

	/**
	 * Returns the company requisite field codes explicitly configured for the selected preset.
	 *
	 * @return list<string>
	 */
	public function getCompanyRequisiteFieldSetFieldCodes(?int $presetId): array
	{
		return $this->getCompanyRequisiteFieldConfiguration($presetId)['fieldSetCodes'];
	}

	/**
	 * Reads only the selected preset and its company FieldSet.
	 *
	 * @return array{presetFieldCodes: list<string>, fieldSetCodes: list<string>}
	 */
	public function getCompanyRequisiteFieldConfiguration(?int $presetId): array
	{
		if (!Loader::includeModule('crm'))
		{
			return self::EMPTY_COMPANY_REQUISITE_CONFIGURATION;
		}

		$presetId ??= $this->getDefaultCompanyRequisitePresetId();
		if ($presetId === null || $presetId <= 0)
		{
			return self::EMPTY_COMPANY_REQUISITE_CONFIGURATION;
		}

		$presetFields = $this->getCompanyRequisitePresetFields($presetId);
		if ($presetFields === null)
		{
			return self::EMPTY_COMPANY_REQUISITE_CONFIGURATION;
		}

		return [
			'presetFieldCodes' => $this->extractFieldCodes($presetFields, 'FIELD_NAME'),
			'fieldSetCodes' => $this->extractFieldCodes(
				$this->getCompanyRequisiteFieldSetFields($presetId) ?? [],
				'name',
			),
		];
	}

	protected function getDefaultCompanyRequisitePresetId(): ?int
	{
		$presetId = Crm\EntityRequisite::getDefaultPresetId(\CCrmOwnerType::Company);

		return $presetId > 0 ? $presetId : null;
	}

	protected function getCompanyRequisitePresetFields(int $presetId): ?array
	{
		$row = Crm\PresetTable::query()
			->setSelect(['SETTINGS'])
			->where('ID', $presetId)
			->where('ENTITY_TYPE_ID', Crm\EntityPreset::Requisite)
			->where('ACTIVE', true)
			->setLimit(1)
			->fetch()
		;
		if (!is_array($row))
		{
			return null;
		}

		$settings = $row['SETTINGS'] ?? [];

		return is_array($settings)
			? Crm\EntityPreset::getSingleInstance()->settingsGetFields($settings)
			: [];
	}

	protected function getCompanyRequisiteFieldSetFields(int $presetId): ?array
	{
		$row = Crm\Model\FieldSetTable::query()
			->setSelect(['FIELDS'])
			->where('CODE', 'def-req-' . \CCrmOwnerType::Company . '-' . $presetId)
			->where('ENTITY_TYPE_ID', \CCrmOwnerType::SmartDocument)
			->where('CLIENT_ENTITY_TYPE_ID', \CCrmOwnerType::Company)
			->where('RQ_PRESET_ID', $presetId)
			->setLimit(1)
			->fetch()
		;

		return isset($row['FIELDS']) && is_array($row['FIELDS']) ? $row['FIELDS'] : null;
	}

	/**
	 * @return list<string>
	 */
	private function extractFieldCodes(array $fields, string $codeKey): array
	{
		$fieldCodes = [];
		foreach ($fields as $field)
		{
			if (!is_array($field))
			{
				continue;
			}

			$fieldCode = $field[$codeKey] ?? null;
			if (is_string($fieldCode) && $fieldCode !== '')
			{
				$fieldCodes[] = $fieldCode;
			}
		}

		return array_values(array_unique($fieldCodes));
	}
}
