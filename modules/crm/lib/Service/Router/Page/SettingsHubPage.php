<?php

namespace Bitrix\Crm\Service\Router\Page;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\SettingsHub;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorOptions;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorRule;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\PageValidator\PermissionValidator;
use Bitrix\Crm\Service\Router\Route;

final class SettingsHubPage extends AbstractPage
{
	private const COMPONENT_NAME = 'bitrix:crm.settings-hub';

	public function component(): Contract\Component
	{
		return new Component(self::COMPONENT_NAME);
	}

	public static function routes(): array
	{
		return [
			(new Route('settings-hub/'))
				->setRelatedComponent(self::COMPONENT_NAME)
			,
		];
	}

	public static function scopes(): array
	{
		return [
			Scope::Crm,
		];
	}

	public static function isActive(): bool
	{
		return Feature::enabled(SettingsHub::class);
	}

	public static function getSidePanelAnchorRules(): array
	{
		if (!self::isActive())
		{
			return [];
		}

		return [
			(new SidePanelAnchorRule('settings-hub/?'))
				->scopes(self::scopes())
				->configureOptions(static function (SidePanelAnchorOptions $options): void {
					$options
						->setWidth(1320)
						->setCacheable(false)
						->setAllowChangeHistory(false)
					;
				})
			,
		];
	}

	protected function getPageValidators(): array
	{
		return [
			...parent::getPageValidators(),
			new PermissionValidator(static function (): bool {
				return Container::getInstance()->getUserPermissions()->entityType()->canReadSomeItemsInCrm();
			}),
		];
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		$sidePanel->pageMode = false;
		$sidePanel->pageModeBackUrl = Container::getInstance()->getRouter()->getRoot();
		$sidePanel->isPlainView = true;
		$sidePanel->isUsePadding = false;
		$sidePanel->isUseToolbar = false;
		$sidePanel->isHideToolbar = true;
	}
}
