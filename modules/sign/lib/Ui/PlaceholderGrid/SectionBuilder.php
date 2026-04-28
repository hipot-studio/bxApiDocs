<?php

namespace Bitrix\Sign\Ui\PlaceholderGrid;

use Bitrix\Sign\Item\Document\Placeholder\HcmLinkPlaceholders;
use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Serializer\ItemPropertyJsonSerializer;
use Bitrix\Sign\Ui\PlaceholderGrid\Item\SectionConfig;

class SectionBuilder
{
	public function __construct(
		private readonly ItemPropertyJsonSerializer $serializer,
	)
	{
	}

	public function buildSections(array $placeholders): array
	{
		return array_values(array_filter(array_map(
			fn($sectionConfig) => $this->buildSectionFromConfig($sectionConfig),
			$this->getSectionsConfig($placeholders),
		)));
	}

	/**
	 * @return SectionConfig[]
	 */
	private function getSectionsConfig(array $placeholders): array
	{
		return [
			new SectionConfig(
				type: SectionType::EMPLOYEE,
				titleMessageCode: 'PLACEHOLDER_LIST_SIGNER_CAPTION',
				subsections: $this->getEmployeeSubsectionsConfig($placeholders),
			),
			new SectionConfig(
				type: SectionType::COMPANY,
				titleMessageCode: 'PLACEHOLDER_LIST_COMPANY_CAPTION',
				items: $placeholders['company'],
			),
			new SectionConfig(
				type: SectionType::SMART_B2E_DOC,
				titleMessageCode: 'PLACEHOLDER_LIST_SMART_B2E_DOC_CAPTION',
				subsections: $this->getDocumentSubsectionsConfig($placeholders),
			),
			new SectionConfig(
				type: SectionType::REPRESENTATIVE,
				titleMessageCode: 'PLACEHOLDER_LIST_ASSIGNEE_CAPTION',
				items: $placeholders['representative'],
			),
		];
	}

	/**
	 * @return SectionConfig[]
	 */
	private function getDocumentSubsectionsConfig(array $placeholders): array
	{
		return [
			new SectionConfig(
				type: SectionType::GENERAL_DATA,
				titleMessageCode: 'PLACEHOLDER_LIST_SMART_B2E_DOC_GENERAL_CAPTION',
				items: $placeholders['b2eDocument'],
			),
			new SectionConfig(
				type: SectionType::EXTERNAL_DATA,
				titleMessageCode: 'PLACEHOLDER_LIST_SMART_B2E_DOC_ADDITIONAL_CAPTION',
				items: $placeholders['externalB2eDocument'],
			),
		];
	}

	/**
	 * @return SectionConfig[]
	 */
	private function getEmployeeSubsectionsConfig(array $placeholders): array
	{
		return [
			new SectionConfig(
				type: SectionType::PERSONAL_DATA,
				titleMessageCode: 'PLACEHOLDER_LIST_EMPLOYEE_PERSONAL_DATA_CAPTION',
				items: $placeholders['employee'],
			),
			new SectionConfig(
				type: SectionType::DYNAMIC_MEMBER_DATA,
				titleMessageCode: 'PLACEHOLDER_LIST_EMPLOYEE_DYNAMIC_MEMBER_DATA_CAPTION',
				items: $placeholders['employeeDynamic'],
			),
		];
	}

	private function buildSectionFromConfig(SectionConfig $config): ?array
	{
		$subsections = null;
		if ($config->subsections !== null)
		{
			$subsections = array_values(array_filter(array_map(
				fn(SectionConfig $subsectionConfig) => $this->buildSectionFromConfig($subsectionConfig),
				$config->subsections,
			)));
		}

		return $this->buildSection(
			$config->type,
			$config->titleMessageCode,
			$config->items,
			$subsections,
		);
	}

	public function buildHcmLinkSections(HcmLinkPlaceholders $hcmLinkPlaceholders): array
	{
		$serialized = $this->serializer->serialize($hcmLinkPlaceholders);

		return array_values(array_filter([
			$this->buildHcmLinkSection(
				$serialized,
				SectionType::EMPLOYEE,
				'PLACEHOLDER_LIST_HCM_LINK_EMPLOYEE_CAPTION',
			),
			$this->buildHcmLinkSection(
				$serialized,
				SectionType::REPRESENTATIVE,
				'PLACEHOLDER_LIST_HCM_LINK_REPRESENTATIVE_CAPTION',
			),
		]));
	}

	private function buildSection(
		SectionType $type,
		string $titleMessageCode,
		?PlaceholderCollection $items = null,
		?array $subsections = null
	): ?array
	{
		if ($subsections !== null)
		{
			$filteredSubsections = array_filter($subsections);
			if (empty($filteredSubsections))
			{
				return null;
			}

			return [
				'type' => $type->value,
				'title' => Loc::getMessage($titleMessageCode),
				'subsections' => array_values($filteredSubsections),
			];
		}

		if ($items !== null && $items->count() > 0)
		{
			return [
				'type' => $type->value,
				'title' => Loc::getMessage($titleMessageCode),
				'items' => $this->serializer->serialize($items),
			];
		}

		return null;
	}

	private function buildHcmLinkSection(array $serialized, SectionType $subsectionType, string $titleMessageCode): ?array
	{
		if (empty($serialized[$subsectionType->value]))
		{
			return null;
		}

		$items = array_merge(...array_map(fn($company) => $company['items'], $serialized[$subsectionType->value]));
		if (empty($items))
		{
			return null;
		}

		return [
			'type' => SectionType::HCM_LINK->value,
			'subsectionType' => $subsectionType->value,
			'title' => Loc::getMessage($titleMessageCode),
			'items' => $items,
		];
	}
}
