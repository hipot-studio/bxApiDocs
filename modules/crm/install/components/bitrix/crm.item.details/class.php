<?php

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

use Bitrix\Crm\AutomatedSolution\CapabilityAccessChecker;
use Bitrix\Crm\Component\EntityDetails\FactoryBased;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\JsCode;
use Bitrix\UI\Buttons\SettingsButton;

Loader::includeModule('crm');

class CrmItemDetailsComponent extends FactoryBased
{
	public function executeComponent()
	{
		$this->init();

		if ($this->getErrors())
		{
			if ($this->tryShowCustomErrors())
			{
				return;
			}
			$this->includeComponentTemplate();

			return;
		}

		$this->executeBaseLogic();

		$this->setBizprocStarterConfig();

		$this->includeComponentTemplate();
	}

	protected function getDeleteMessage(): string
	{
		return (string)Loc::getMessage('CRM_TYPE_ITEM_DELETE');
	}

	protected function getExtras(): array
	{
		$extras = parent::getExtras();

		$entityTypeId = $this->getEntityTypeID();
		$section = (
			IntranetManager::isEntityTypeInCustomSection($entityTypeId)
				? Dictionary::SECTION_CUSTOM
				: Dictionary::SECTION_DYNAMIC
		);

		$extras['ANALYTICS'] = [
			'c_section' => $section,
			'c_sub_section' => \Bitrix\Crm\Integration\Analytics\Dictionary::SUB_SECTION_DETAILS,
		];

		return $extras;
	}

	public function getEditorConfig(): array
	{
		$config = parent::getEditorConfig();

		if ($this->isLockedAutomatedSolution())
		{
			$config['ENABLE_USER_FIELD_CREATION'] = false;
			$config['USER_FIELD_CREATE_SIGNATURE'] = '';
		}

		return $config;
	}

	protected function getSettingsToolbarButton(): SettingsButton
	{
		$button = parent::getSettingsToolbarButton();

		if ($this->isLockedAutomatedSolution())
		{
			$this->bindClickEventToLockedButton($button);
		}

		return $button;
	}

	protected function getDocumentToolbarButton(): Button
	{
		$button = parent::getDocumentToolbarButton();

		if ($this->isLockedAutomatedSolution())
		{
			$this->bindClickEventToLockedButton($button);

			if (method_exists($button, 'setDocumentButtonConfig'))
			{
				$button->setDocumentButtonConfig([]);
			}
		}

		return $button;
	}

	private function bindClickEventToLockedButton(Button $button): void
	{
		$button->bindEvent(
			'click',
			new JsCode('(new BX.UI.FeaturePromoter({ code: \'limit_v2_crm_automated_solution_marketplace\' })).show()'),
		);
	}

	private function isLockedAutomatedSolution(): bool
	{
		return CapabilityAccessChecker::getInstance()->isLockedEntityType($this->getEntityTypeID());
	}
}
