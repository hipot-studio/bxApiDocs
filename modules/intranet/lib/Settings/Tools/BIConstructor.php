<?php

namespace Bitrix\Intranet\Settings\Tools;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Localization\Loc;

class BIConstructor extends Tool
{
	public function getId(): string
	{
		return 'crm_bi';
	}

	public function getName(): string
	{
		return Loc::getMessage('INTRANET_SETTINGS_TOOLS_BI_CONSTRUCTOR_MAIN') ?? '';
	}

	public function isAvailable(): bool
	{
		return ModuleManager::isModuleInstalled('biconnector');
	}

	public function getSubgroupsIds(): array
	{
		return [];
	}

	public function getSubgroups(): array
	{
		return [];
	}

	public function getLeftMenuPath(): ?string
	{
		return SITE_DIR . 'bi/dashboard/';
	}

	public function getSettingsPath(): ?string
	{
		return $this->getLeftMenuPath();
	}

	public function getMenuItemId(): ?string
	{
		return 'menu_bi_constructor';
	}

	public function disable($notifySupersetInitializer = true): void
	{
		parent::disable();

		if (!Loader::includeModule('biconnector'))
		{
			return;
		}

		if ($notifySupersetInitializer && SupersetInitializer::isSupersetExist())
		{
			SupersetInitializer::onDisableBiBuilderTool();
		}
	}

	public function enable(): void
	{
		parent::enable();

		if (!Loader::includeModule('biconnector'))
		{
			return;
		}

		SupersetInitializer::onEnableBiBuilderTool();
	}

	public function isNeedDisableConfirmation(): bool
	{
		if (!Loader::includeModule('biconnector'))
		{
			return false;
		}

		if (!ConfigContainer::getConfigContainer()->isPortalIdVerified() && !SupersetInitializer::isRebindRequired())
		{
			return false;
		}

		return SupersetInitializer::isSupersetExist();
	}

	public function getDisableConfirmationText(): ?string
	{
		if (Loader::includeModule('biconnector') && SupersetInitializer::isRebindRequired())
		{
			return Loc::getMessage('INTRANET_SETTINGS_TOOLS_BI_CONSTRUCTOR_DISABLE_CONFIRMATION_TEXT_REBIND');
		}

		return Loc::getMessage('INTRANET_SETTINGS_TOOLS_BI_CONSTRUCTOR_DISABLE_CONFIRMATION_TEXT');
	}
}
