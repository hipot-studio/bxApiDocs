<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\FieldMetadata;

use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\SystemFieldTitle;
use Bitrix\Crm\V2\Internal\Service\Item\FieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\OwnerType;

final class SystemFieldTitleProvider
{
	private const COMMON_LANGUAGE_SOURCE_FILE = 'lib/Service/Localization.php';

	private const GENERAL_LEGACY_LANGUAGE_SOURCE_DIRECTORY = 'classes/general';

	private const GENERAL_LEGACY_LANGUAGE_SOURCE_FILE = self::GENERAL_LEGACY_LANGUAGE_SOURCE_DIRECTORY . '/crm_document.php';

	private const CLOSED_LEGACY_LANGUAGE_SOURCE_FILE = self::GENERAL_LEGACY_LANGUAGE_SOURCE_DIRECTORY . '/crm_quote.php';

	private const LEAD_CLOSED_LEGACY_LANGUAGE_SOURCE_FILE = 'lib/filter/leaddataprovider.php';

	private const LANGUAGE_FILE_BY_ENTITY_TYPE = [
		OwnerType::LEAD => 'crm_lead.php',
		OwnerType::DEAL => 'crm_deal.php',
		OwnerType::CONTACT => 'crm_contact.php',
		OwnerType::COMPANY => 'crm_company.php',
		OwnerType::QUOTE => 'crm_quote.php',
		OwnerType::SMART_INVOICE => 'crm_invoice.php',
	];

	private const COMMON_LEGACY_FIELD_NAMES = [
		'beginTime' => 'BEGINDATE',
		'closeTime' => 'CLOSEDATE',
		'contactId' => 'CONTACT_ID',
		'contactsId' => 'CONTACT_IDS',
		'categoryId' => 'CATEGORY',
		'closed' => 'CLOSED',
		'lastActivityTime' => 'LAST_ACTIVITY_TIME_2',
		'lastCommunication' => 'NAME_LAST_COMMUNICATION_TIME',
		'locationId' => 'LOCATION',
		'movedById' => 'MOVED_BY_V2',
		'movedBy' => 'MOVED_BY_V2',
		'movedTime' => 'MOVED_TIME',
		'stageSemanticId' => 'STAGE_SEMANTIC_ID',
		'stageSemantic' => 'STAGE_SEMANTIC_ID',
		'hasIm' => 'HAS_IMOL',
		'taxValue' => 'TAX_VALUE',
		'taxValueAccount' => 'TAX_VALUE_ACCOUNT',
	];

	private const MULTIFIELD_LEGACY_FIELD_NAMES = [
		'phone' => 'MULTI_PHONE',
		'email' => 'MULTI_EMAIL',
		'web' => 'MULTI_WEB',
		'im' => 'MULTI_IM',
	];

	private const OBSERVER_LEGACY_FIELD_NAMES = [
		'observers' => 'OBSERVER_IDS',
		'observersId' => 'OBSERVER_IDS',
	];

	private const LEGACY_FIELD_NAMES_BY_ENTITY_TYPE = [
		OwnerType::LEAD => [
			'closeTime' => 'DATE_CLOSED',
			'birthdayTime' => 'BIRTHDATE',
		],
		OwnerType::DEAL => [
			'beginTime' => 'BEGINDATE',
			'closeTime' => 'CLOSEDATE',
			'type' => 'TYPE_ID',
			'contactId' => 'CONTACT_ID',
			'contactsId' => 'CONTACT_IDS',
		],
		OwnerType::CONTACT => [
			'birthdayTime' => 'BIRTHDATE',
			'type' => 'TYPE_ID',
			'companies' => 'COMPANY',
			'companiesId' => 'COMPANY_ID',
			'export' => 'EXPORT_NEW',
		],
		OwnerType::COMPANY => [
			'type' => 'COMPANY_TYPE',
		],
		OwnerType::QUOTE => [
			'beginTime' => 'BEGINDATE',
			'closeTime' => 'CLOSEDATE',
			'actualTime' => 'ACTUAL_DATE',
			'personType' => 'PERSON_TYPE_ID',
		],
		OwnerType::SMART_INVOICE => [
			'beginTime' => 'BEGIN_DATE',
			'closeTime' => 'CLOSE_DATE',
			'closed' => 'CLOSED',
		],
	];

	public function getTitle(EntityType $entityType, string $fieldName): SystemFieldTitle
	{
		return new SystemFieldTitle($this->getMessageCandidates($entityType, $fieldName), $fieldName);
	}

	/** @return array<int, array{0: string, 1: string}> */
	private function getMessageCandidates(EntityType $entityType, string $fieldName): array
	{
		$legacyFieldNames = $this->getLegacyFieldNames($entityType, $fieldName);
		$commonLanguageFile = self::COMMON_LANGUAGE_SOURCE_FILE;
		$entityLanguageFile = $this->getEntityLanguageFile($entityType);
		$multifieldLegacyCandidates = $this->getMultifieldLegacyCandidates($entityType, $fieldName);
		$observerLegacyCandidates = $this->getObserverLegacyCandidates($entityType, $fieldName);
		$contactBindingLegacyCandidates = $this->getContactBindingLegacyCandidates($entityType, $fieldName);

		if ($entityType->isSmartProcess())
		{
			return array_merge(
				$this->buildCandidates(
					$legacyFieldNames,
					['CRM_TYPE_ITEM_FIELD_', 'CRM_COMMON_'],
					$commonLanguageFile,
				),
				$this->getClosedLegacyCandidates($entityType, $fieldName),
				$multifieldLegacyCandidates,
				$observerLegacyCandidates,
				$contactBindingLegacyCandidates,
			);
		}

		if ($entityType->getId() === OwnerType::SMART_INVOICE)
		{
			$candidates = $this->buildCandidates(
				$legacyFieldNames,
				['CRM_TYPE_SMART_INVOICE_FIELD_', 'CRM_TYPE_ITEM_FIELD_', 'CRM_COMMON_'],
				$commonLanguageFile,
			);

			if ($entityLanguageFile !== null)
			{
				$candidates = array_merge(
					$candidates,
					$this->buildCandidates($legacyFieldNames, ['CRM_INVOICE_FIELD_'], $entityLanguageFile),
				);
			}
			$candidates = array_merge(
				$candidates,
				$this->buildCandidates(
					$legacyFieldNames,
					['CRM_QUOTE_FIELD_'],
					self::CLOSED_LEGACY_LANGUAGE_SOURCE_FILE,
				),
			);
			$candidates = array_merge($candidates, $multifieldLegacyCandidates);
			$candidates = array_merge($candidates, $observerLegacyCandidates);
			$candidates = array_merge($candidates, $contactBindingLegacyCandidates);

			return $candidates;
		}

		if ($entityLanguageFile === null)
		{
			return [];
		}

		$entityName = $entityType->getName();
		$candidates = $this->buildCandidates(
			$legacyFieldNames,
			["CRM_{$entityName}_FIELD_"],
			$entityLanguageFile,
		);

		return array_merge(
			$candidates,
			$this->buildCandidates(
				$legacyFieldNames,
				['CRM_TYPE_ITEM_FIELD_', 'CRM_COMMON_'],
				$commonLanguageFile,
			),
			$this->getClosedLegacyCandidates($entityType, $fieldName),
			$multifieldLegacyCandidates,
			$observerLegacyCandidates,
			$contactBindingLegacyCandidates,
		);
	}

	/** @return array<int, array{0: string, 1: string}> */
	private function getMultifieldLegacyCandidates(EntityType $entityType, string $fieldName): array
	{
		if (!EntityTypeSettings::of($entityType)->hasMultifields())
		{
			return [];
		}

		$legacyFieldName = self::MULTIFIELD_LEGACY_FIELD_NAMES[$fieldName] ?? null;
		if ($legacyFieldName === null)
		{
			return [];
		}

		return $this->buildCandidates([$legacyFieldName], ['CRM_FIELD_'], self::GENERAL_LEGACY_LANGUAGE_SOURCE_FILE);
	}

	/** @return array<int, array{0: string, 1: string}> */
	private function getClosedLegacyCandidates(EntityType $entityType, string $fieldName): array
	{
		if ($fieldName !== 'closed'
			|| ($entityType->getId() !== OwnerType::LEAD && !$entityType->isSmartProcess()))
		{
			return [];
		}

		if ($entityType->getId() === OwnerType::LEAD)
		{
			return $this->buildCandidates(
				['STATUS_PROCESSED'],
				['CRM_LEAD_FILTER_'],
				self::LEAD_CLOSED_LEGACY_LANGUAGE_SOURCE_FILE,
			);
		}

		return $this->buildCandidates(
			['CLOSED'],
			['CRM_QUOTE_FIELD_'],
			self::CLOSED_LEGACY_LANGUAGE_SOURCE_FILE,
		);
	}

	/** @return array<int, array{0: string, 1: string}> */
	private function getObserverLegacyCandidates(EntityType $entityType, string $fieldName): array
	{
		if (!EntityTypeSettings::of($entityType)->hasObservers())
		{
			return [];
		}

		$legacyFieldName = self::OBSERVER_LEGACY_FIELD_NAMES[$fieldName] ?? null;
		if ($legacyFieldName === null)
		{
			return [];
		}

		return $this->buildCandidates([$legacyFieldName], ['CRM_FIELD_'], self::GENERAL_LEGACY_LANGUAGE_SOURCE_FILE);
	}

	/** @return array<int, array{0: string, 1: string}> */
	private function getContactBindingLegacyCandidates(EntityType $entityType, string $fieldName): array
	{
		if (!EntityTypeSettings::of($entityType)->hasContactBindings() || $fieldName !== 'contactsId')
		{
			return [];
		}

		return $this->buildCandidates(['CONTACT_IDS'], ['CRM_DOCUMENT_FIELD_'], self::GENERAL_LEGACY_LANGUAGE_SOURCE_FILE);
	}

	/**
	 * @param string[] $legacyFieldNames
	 * @param string[] $prefixes
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function buildCandidates(array $legacyFieldNames, array $prefixes, string $sourceFile): array
	{
		$candidates = [];
		foreach ($legacyFieldNames as $legacyFieldName)
		{
			foreach ($prefixes as $prefix)
			{
				$candidates[] = [
					$sourceFile,
					$prefix . strtoupper($legacyFieldName),
				];
			}
		}

		return $candidates;
	}

	/** @return string[] */
	private function getLegacyFieldNames(EntityType $entityType, string $fieldName): array
	{
		$fieldRegistry = FieldRegistry::getInstance($entityType);
		$legacyFieldNames = [
			...($this->getExplicitLegacyFieldNames($entityType, $fieldName)),
			$fieldName,
		];
		if ($fieldRegistry->getField($fieldName) === null && $fieldRegistry->getField("{$fieldName}Id") !== null)
		{
			$legacyFieldNames[] = "{$fieldName}Id";
		}
		if (str_ends_with($fieldName, 'Id'))
		{
			$baseFieldName = substr($fieldName, 0, -2);
			if ($fieldRegistry->getField($baseFieldName) !== null)
			{
				$legacyFieldNames[] = $baseFieldName;
			}
		}

		return array_map(
			static fn(string $name): string => $fieldRegistry->getField($name)?->ormName ?? $name,
			array_values(array_unique($legacyFieldNames)),
		);
	}

	/** @return string[] */
	private function getExplicitLegacyFieldNames(EntityType $entityType, string $fieldName): array
	{
		$legacyFieldName = self::LEGACY_FIELD_NAMES_BY_ENTITY_TYPE[$entityType->getId()][$fieldName]
			?? self::COMMON_LEGACY_FIELD_NAMES[$fieldName]
			?? self::OBSERVER_LEGACY_FIELD_NAMES[$fieldName]
			?? null;

		return $legacyFieldName === null ? [] : [$legacyFieldName];
	}

	private function getEntityLanguageFile(EntityType $entityType): ?string
	{
		$fileName = self::LANGUAGE_FILE_BY_ENTITY_TYPE[$entityType->getId()] ?? null;
		if ($fileName === null)
		{
			return null;
		}

		return self::GENERAL_LEGACY_LANGUAGE_SOURCE_DIRECTORY . "/{$fileName}";
	}

}
