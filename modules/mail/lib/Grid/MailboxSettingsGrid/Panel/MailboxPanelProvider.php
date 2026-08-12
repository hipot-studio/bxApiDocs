<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Panel;

use Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action\ConfigureCrmAction;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action\DisableCalendarAction;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action\DisableCrmAction;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action\DisconnectMailboxAction;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action\EnableCalendarAction;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action\EnableCrmAction;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main\Grid\Panel\Action\DataProvider;
use Bitrix\Main\Loader;

class MailboxPanelProvider extends DataProvider
{
	public function prepareActions(): array
	{
		$settings = $this->getSettings();

		$actions = [
			new DisconnectMailboxAction($settings),
		];

		if ($this->isCrmAvailableForCurrentUser())
		{
			$actions[] = new EnableCrmAction($settings);
			$actions[] = new DisableCrmAction($settings);
			$actions[] = new ConfigureCrmAction($settings);
		}

		if (Loader::includeModule('calendar'))
		{
			$actions[] = new EnableCalendarAction($settings);
			$actions[] = new DisableCalendarAction($settings);
		}

		return $actions;
	}

	private function isCrmAvailableForCurrentUser(): bool
	{
		return Loader::includeModule('crm')
			&& MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm()
			&& Feature::isCrmAvailable()
		;
	}
}
