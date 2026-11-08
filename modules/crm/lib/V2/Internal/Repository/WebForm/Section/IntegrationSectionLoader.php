<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\Ads\Internals\AdsFormLinkTable;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\AdFieldMapping;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\AdIntegration;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\Integrations;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\WebForm\Internals\FormFieldMappingTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * @internal
 */
final class IntegrationSectionLoader extends WebFormSectionLoader
{
	private const FIELD_MAPPING_REFERENCE = 'FIELD_MAPPING';
	private const FIELD_MAPPING_FORM_LINK_ID_COLUMN = 'FIELD_MAPPING_FORM_LINK_ID';

	private const ADS_PATH = Integrations::FIELD_ADS;
	private const FIELD_MAPPINGS_PATH = self::ADS_PATH . '.' . AdIntegration::FIELD_FIELD_MAPPINGS;

	private const COLUMN_BY_PATH = [
		self::ADS_PATH . '.' . AdIntegration::FIELD_DIRECTION => 'LINK_DIRECTION',
		self::ADS_PATH . '.' . AdIntegration::FIELD_PROVIDER_CODE => 'ADS_TYPE',
		self::ADS_PATH . '.' . AdIntegration::FIELD_ACCOUNT_ID => 'ADS_ACCOUNT_ID',
		self::ADS_PATH . '.' . AdIntegration::FIELD_ACCOUNT_NAME => 'ADS_ACCOUNT_NAME',
		self::ADS_PATH . '.' . AdIntegration::FIELD_FORM_ID => 'ADS_FORM_ID',
		self::ADS_PATH . '.' . AdIntegration::FIELD_FORM_NAME => 'ADS_FORM_NAME',
		self::FIELD_MAPPINGS_PATH . '.' . AdFieldMapping::FIELD_CRM_FIELD_NAME => 'CRM_FIELD_KEY',
		self::FIELD_MAPPINGS_PATH . '.' . AdFieldMapping::FIELD_ADS_FIELD_NAME => 'ADS_FIELD_KEY',
	];

	public function getSectionName(): string
	{
		return WebForm::SECTION_INTEGRATIONS;
	}

	/**
	 * @param string[] $selectedFields
	 */
	protected function createEmptySection(array $selectedFields): mixed
	{
		return new Integrations(
			whatsAppEnabled: null,
			ads: self::hasAdsSelected($selectedFields) ? [] : null,
		);
	}

	/**
	 * @param int[] $formIds
	 * @param string[] $selectedFields
	 * @return array<int, Integrations>
	 */
	protected function loadSelected(array $formIds, array $selectedFields): array
	{
		if (!self::hasAdsSelected($selectedFields))
		{
			return [];
		}

		$selectedFieldMap = array_fill_keys($selectedFields, true);
		$fieldMappingsSelected = self::hasFieldMappingsSelected($selectedFields);
		$columns = ['WEBFORM_ID', 'ID'];
		foreach (self::COLUMN_BY_PATH as $path => $column)
		{
			if (
				!str_starts_with($path, self::FIELD_MAPPINGS_PATH . '.')
				&& self::isLeafSelected($selectedFieldMap, $path)
			)
			{
				$columns[] = $column;
			}
		}

		$query = AdsFormLinkTable::query();
		if ($fieldMappingsSelected)
		{
			$query->registerRuntimeField(
				new Reference(
					self::FIELD_MAPPING_REFERENCE,
					FormFieldMappingTable::class,
					Join::on('this.ID', 'ref.FORM_LINK_ID'),
					['join_type' => Join::TYPE_LEFT],
				),
			);
			$columns[self::FIELD_MAPPING_FORM_LINK_ID_COLUMN] =
				self::FIELD_MAPPING_REFERENCE . '.FORM_LINK_ID';
			foreach (self::COLUMN_BY_PATH as $path => $column)
			{
				if (
					str_starts_with($path, self::FIELD_MAPPINGS_PATH . '.')
					&& self::isLeafSelected($selectedFieldMap, $path)
				)
				{
					$columns[$column] = self::FIELD_MAPPING_REFERENCE . '.' . $column;
				}
			}
		}

		$query
			->setSelect(array_unique($columns))
			->whereIn('WEBFORM_ID', $formIds)
			->setOrder(self::getOrder($fieldMappingsSelected))
		;

		$adsByFormId = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$formId = (int)$row['WEBFORM_ID'];
			$linkId = (int)$row['ID'];
			$adsByFormId[$formId][$linkId] ??= [
				'row' => $row,
				'fieldMappings' => $fieldMappingsSelected ? [] : null,
			];

			if (
				$fieldMappingsSelected
				&& ($row[self::FIELD_MAPPING_FORM_LINK_ID_COLUMN] ?? null) !== null
			)
			{
				$adsByFormId[$formId][$linkId]['fieldMappings'][] = self::createFieldMapping(
					$row,
					$selectedFieldMap,
				);
			}
		}

		return self::createIntegrations($adsByFormId, $selectedFieldMap);
	}

	/**
	 * @return array<string, string>
	 */
	private static function getOrder(bool $fieldMappingsSelected): array
	{
		$order = [
			'WEBFORM_ID' => 'ASC',
			'ID' => 'ASC',
		];
		if ($fieldMappingsSelected)
		{
			$order[self::FIELD_MAPPING_REFERENCE . '.FORM_LINK_ID'] = 'ASC';
			$order[self::FIELD_MAPPING_REFERENCE . '.CRM_FIELD_KEY'] = 'ASC';
			$order[self::FIELD_MAPPING_REFERENCE . '.ADS_FIELD_KEY'] = 'ASC';
		}

		return $order;
	}

	/**
	 * @param array<int, array<int, array{
	 *     row: array<string, mixed>,
	 *     fieldMappings: null|AdFieldMapping[],
	 * }>> $adsByFormId
	 * @param array<string, true> $selectedFields
	 * @return array<int, Integrations>
	 */
	private static function createIntegrations(array $adsByFormId, array $selectedFields): array
	{
		$result = [];
		foreach ($adsByFormId as $formId => $adsByLinkId)
		{
			$ads = [];
			foreach ($adsByLinkId as $ad)
			{
				$ads[] = self::createAdIntegration(
					$ad['row'],
					$ad['fieldMappings'],
					$selectedFields,
				);
			}
			$result[$formId] = new Integrations(whatsAppEnabled: null, ads: $ads);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param null|AdFieldMapping[] $fieldMappings
	 * @param array<string, true> $selectedFields
	 */
	private static function createAdIntegration(
		array $row,
		?array $fieldMappings,
		array $selectedFields,
	): AdIntegration
	{
		return new AdIntegration(
			direction: self::integer(
				$row,
				$selectedFields,
				self::ADS_PATH . '.' . AdIntegration::FIELD_DIRECTION,
			),
			providerCode: self::requiredString(
				$row,
				$selectedFields,
				self::ADS_PATH . '.' . AdIntegration::FIELD_PROVIDER_CODE,
			),
			accountId: self::requiredString(
				$row,
				$selectedFields,
				self::ADS_PATH . '.' . AdIntegration::FIELD_ACCOUNT_ID,
			),
			accountName: self::nullableString(
				$row,
				$selectedFields,
				self::ADS_PATH . '.' . AdIntegration::FIELD_ACCOUNT_NAME,
			),
			formId: self::requiredString(
				$row,
				$selectedFields,
				self::ADS_PATH . '.' . AdIntegration::FIELD_FORM_ID,
			),
			formName: self::nullableString(
				$row,
				$selectedFields,
				self::ADS_PATH . '.' . AdIntegration::FIELD_FORM_NAME,
			),
			fieldMappings: $fieldMappings,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function createFieldMapping(array $row, array $selectedFields): AdFieldMapping
	{
		return new AdFieldMapping(
			crmFieldName: self::requiredString(
				$row,
				$selectedFields,
				self::FIELD_MAPPINGS_PATH . '.' . AdFieldMapping::FIELD_CRM_FIELD_NAME,
			),
			adsFieldName: self::requiredString(
				$row,
				$selectedFields,
				self::FIELD_MAPPINGS_PATH . '.' . AdFieldMapping::FIELD_ADS_FIELD_NAME,
			),
		);
	}

	/**
	 * @param string[] $selectedFields
	 */
	private static function hasAdsSelected(array $selectedFields): bool
	{
		foreach ($selectedFields as $selectedField)
		{
			if (
				$selectedField === self::ADS_PATH
				|| str_starts_with($selectedField, self::ADS_PATH . '.')
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string[] $selectedFields
	 */
	private static function hasFieldMappingsSelected(array $selectedFields): bool
	{
		foreach ($selectedFields as $selectedField)
		{
			if (
				$selectedField === self::ADS_PATH
				|| $selectedField === self::FIELD_MAPPINGS_PATH
				|| str_starts_with($selectedField, self::FIELD_MAPPINGS_PATH . '.')
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, true> $selectedFields
	 */
	private static function isLeafSelected(array $selectedFields, string $path): bool
	{
		while (true)
		{
			if (isset($selectedFields[$path]))
			{
				return true;
			}

			$separatorPosition = strrpos($path, '.');
			if ($separatorPosition === false)
			{
				return false;
			}

			$path = substr($path, 0, $separatorPosition);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function integer(array $row, array $selectedFields, string $path): ?int
	{
		if (!self::isLeafSelected($selectedFields, $path))
		{
			return null;
		}

		return (int)($row[self::COLUMN_BY_PATH[$path]] ?? 0);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function requiredString(array $row, array $selectedFields, string $path): ?string
	{
		if (!self::isLeafSelected($selectedFields, $path))
		{
			return null;
		}

		return (string)($row[self::COLUMN_BY_PATH[$path]] ?? '');
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function nullableString(array $row, array $selectedFields, string $path): ?string
	{
		if (!self::isLeafSelected($selectedFields, $path))
		{
			return null;
		}

		$value = $row[self::COLUMN_BY_PATH[$path]] ?? null;

		return $value === null ? null : (string)$value;
	}
}
