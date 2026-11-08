<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\AiAgentsEntrySource;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\AiAgentsScenarioAvailability;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\AiAgentsScenarioCatalog;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\AiAgentsSection;
use Bitrix\Iblock\Component\Tools;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class BIConnectorAiAgentsComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!Loader::includeModule('biconnector') || !AiAgentsSection::isAvailable())
		{
			Tools::process404('', true, true, true);

			return;
		}

		global $APPLICATION;
		$APPLICATION->SetTitle(Loc::getMessage('BICONNECTOR_AI_AGENTS_TITLE'));

		$this->arResult['ENTRY_SOURCE'] = AiAgentsEntrySource::normalize($this->request->getQuery('from'));
		$this->arResult['SHOW_SCENARIO_CARDS'] = Feature::isAiAgentsCardsEnabled();
		if ($this->arResult['SHOW_SCENARIO_CARDS'])
		{
			$this->arResult['SCENARIO_CARDS'] = AiAgentsScenarioCatalog::getCards();
			$this->arResult['SCENARIO_AVAILABILITY'] = AiAgentsScenarioAvailability::check();
		}
		else
		{
			$this->arResult['SCENARIO_CARDS'] = [];
		}

		$this->includeComponentTemplate();
	}
}
