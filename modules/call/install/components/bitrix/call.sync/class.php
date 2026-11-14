<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\Intranet\SyncPageRouter;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

class CallSyncComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		$this->includeComponentLang('class.php');

		// IncludeComponent does not load the module: without this call the Bitrix\Call namespace stays unregistered
		if (!Loader::includeModule('call'))
		{
			ShowError((string)Loc::getMessage('CALL_SYNC_MODULE_NOT_INSTALLED'));

			return;
		}

		$request = Context::getCurrent()->getRequest();
		$requestedPage = (string)$request->getRequestedPage();

		$redirectPath = SyncPageRouter::getRedirectPath($requestedPage, SyncPageRouter::isCallsPageAvailable());
		if ($redirectPath !== null)
		{
			$uri = new Uri($request->getRequestUri());
			$uri->setPath($redirectPath);

			LocalRedirect($uri->getPathQuery());

			return;
		}

		$page = SyncPageRouter::resolvePage($requestedPage);
		$title = $this->getPageTitle($page);

		$this->arResult['PAGE_TITLE'] = $title;

		// the name of the AI assistant depends on the region, which the calls page in the browser cannot learn itself
		if ($page === SyncPageRouter::PAGE_CALLS)
		{
			$this->arResult['COPILOT_NAME'] = CallAISettings::getCopilotName();
		}

		$this->setBrowserTabTitle($title);
		$this->includeComponentTemplate($page);
	}

	private function getPageTitle(string $page): string
	{
		// no default arm on purpose: a page added without its title key must fail here, not fall back
		$titleKey = match ($page)
		{
			SyncPageRouter::PAGE_LANDING => 'CALL_COMPONENT_SYNC_PAGE_TITLE',
			SyncPageRouter::PAGE_CALLS => 'CALL_SYNC_PAGE_TITLE_CALLS',
		};

		return (string)Loc::getMessage($titleKey);
	}

	/**
	 * The tab of the browser only: SetTitle() would put the phrase into the header of the page as well,
	 * and neither page wants it there.
	 */
	private function setBrowserTabTitle(string $title): void
	{
		global $APPLICATION;

		$APPLICATION->SetPageProperty('title', $title);
	}
}
