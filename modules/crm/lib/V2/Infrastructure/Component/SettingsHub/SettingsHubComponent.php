<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\SettingsHubCapabilities;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\Pull\SettingsHubPull;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use CBitrixComponent;

class SettingsHubComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$permissions = Container::getInstance()->getUserPermissions();
		$dataProvider = ServiceLocator::getInstance()->get(SettingsHubBootstrapProvider::class);
		$dataResult = $dataProvider->get($permissions);
		if (!$dataResult->isSuccess())
		{
			$this->renderError($dataResult->getErrorMessages()[0] ?? null);

			return;
		}

		$bootstrap = $dataResult->getData();
		$this->arResult['containerId'] = 'crm-settings-hub-' . $this->randString();
		$this->arResult['bootstrap'] = [
			'settings' => $bootstrap['settings'],
			'navigation' => $bootstrap['navigation'],
			'features' => [
				'capabilitiesEnabled' => Feature::enabled(SettingsHubCapabilities::class),
			],
		];

		$userId = (int)CurrentUser::get()->getId();
		if ($userId > 0 && $permissions->entityType()->canReadSomeItemsInCrm())
		{
			ServiceLocator::getInstance()->get(SettingsHubPull::class)->subscribe($userId);
		}

		$this->includeComponentTemplate();
	}

	private function renderError(?string $title): void
	{
		global $APPLICATION;

		$APPLICATION->IncludeComponent(
			'bitrix:ui.info.error',
			'',
			[
				'TITLE' => $title,
				'DESCRIPTION' => '',
			],
		);
	}
}
