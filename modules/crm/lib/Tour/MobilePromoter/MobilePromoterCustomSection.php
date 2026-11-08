<?php

namespace Bitrix\Crm\Tour\MobilePromoter;

use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Main\Localization\Loc;

class MobilePromoterCustomSection extends MobilePromoter
{
	protected const OPTION_NAME_SHOW_COUNT = 'mobile_promoter_dynamic_show_count';
	private ?int $customSectionId = null;

	public function setCustomSection(int $customSectionId)
	{
		$customSectionId = $customSectionId > 0 ? $customSectionId : null;
		$this->customSectionId = $customSectionId;

		return $this;
	}

	protected function getIntent(): string
	{
		if ($this->customSectionId === null)
		{
			return parent::getIntent();
		}

		return 'preset_custom_section_id' . $this->customSectionId;
	}

	protected function canShow(): bool
	{
		if (!parent::canShow())
		{
			return false;
		}

		foreach ($this->getEntityTypeIdsInCurrentCustomSection() as $entityTypeId)
		{
			if ($this->hasItemsByAssigned($entityTypeId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return int[]
	 */
	private function getEntityTypeIdsInCurrentCustomSection(): array
	{
		if ($this->customSectionId === null)
		{
			return [];
		}

		foreach (IntranetManager::getCustomSections() ?? [] as $customSection)
		{
			if ($customSection->getId() !== $this->customSectionId)
			{
				continue;
			}

			return IntranetManager::getEntityTypesInCustomSection((string)$customSection->getCode());
		}

		return [];
	}

	protected function getTitle(): string
	{
		return Loc::getMessage('CRM_TOUR_MOBILE_PROMOTER_CUSTOM_SECTION_TITLE');
	}

	protected function getContent(): string
	{
		return
			'<ul class="ui-mobile-promoter__popup-list">'
			. '<li class="ui-mobile-promoter__popup-list-item">' . Loc::getMessage('CRM_TOUR_MOBILE_PROMOTER_CUSTOM_SECTION_LIST_ITEM_1') . '</li>'
			. '<li class="ui-mobile-promoter__popup-list-item">' . Loc::getMessage('CRM_TOUR_MOBILE_PROMOTER_CUSTOM_SECTION_LIST_ITEM_2') . '</li>'
			. '<li class="ui-mobile-promoter__popup-list-item">' . Loc::getMessage('CRM_TOUR_MOBILE_PROMOTER_CUSTOM_SECTION_LIST_ITEM_3') . '</li>'
			. '</ul>'
			. '<div class="ui-mobile-promoter__popup-desc">' . Loc::getMessage('CRM_TOUR_MOBILE_PROMOTER_CUSTOM_SECTION_DESC') . '</div>'
			. '<div class="ui-mobile-promoter__popup-info">' . Loc::getMessage('CRM_TOUR_MOBILE_PROMOTER_CUSTOM_SECTION_INFO') . '</div>';
	}

	protected function getAnalytics(): array
	{
		return [
			'c_section' => 'custom_section',
		];
	}
}
