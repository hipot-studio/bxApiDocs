<?php

namespace Bitrix\Crm\Integration\Intranet\SystemPageProvider;

use Bitrix\Crm\Integration\Intranet\SystemPageProvider;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\CustomSection\DataStructures;
use Bitrix\Intranet\CustomSection\DataStructures\CustomSection;
use Bitrix\Intranet\CustomSection\DataStructures\CustomSectionPage;
use Bitrix\Intranet\CustomSection\Provider\Component;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

class EventsPage extends SystemPageProvider
{
	public const CODE = 'events';
	protected const DEFAULT_SETTINGS = [];
	protected const SORT = 999999;

	public static function getComponent(string $pageSettings, Uri $url): ?Component
	{
		$sectionCode = explode(self::SEPARATOR, $pageSettings)[1];

		$params = [
			'EVENT_ENTITY_LINK' => 'Y',
			'ENABLE_CONTROL_PANEL' => false,
			'CUSTOM_SECTION_CODE' => $sectionCode,
		];

		return (new Component())
			->setComponentName('bitrix:crm.event.view')
			->setComponentTemplate('')
			->setComponentParams($params)
		;
	}

	public static function getPageInstance(DataStructures\CustomSection $section): ?CustomSectionPage
	{
		$settingsArr = [self::CODE, $section->getCode(), ...self::DEFAULT_SETTINGS];
		$settings = implode(self::SEPARATOR, $settingsArr);

		return (new CustomSectionPage())
			->setCode(self::CODE)
			->setTitle(Loc::getMessage('CRM_INTEGRATION_INTRANET_EVENTS_PAGE_TITLE'))
			->setSort(self::SORT)
			->setSettings($settings)
			->setModuleId('crm')
			->setDisabledInCtrlPanel(true)
		;
	}

	public static function isPageAvailable(CustomSection $section): bool
	{
		$automatedSolution = Container::getInstance()
			->getAutomatedSolutionManager()
			->getExistingAutomatedSolutions()[$section->getId()] ?? null
		;

		if ($automatedSolution === null)
		{
			return false;
		}

		return Container::getInstance()
			->getUserPermissions()
			->automatedSolutionEvent()
			->canRead($automatedSolution['ID'])
		;
	}
}
