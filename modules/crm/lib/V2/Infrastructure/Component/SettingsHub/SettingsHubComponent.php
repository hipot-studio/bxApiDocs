<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
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

		$dataProvider = ServiceLocator::getInstance()->get(SettingsHubBootstrapProvider::class);
		$dataResult = $dataProvider->get(Container::getInstance()->getUserPermissions());
		if (!$dataResult->isSuccess())
		{
			global $APPLICATION;

			$APPLICATION->IncludeComponent(
				'bitrix:ui.info.error',
				'',
				[
					'TITLE' => $dataResult->getErrorMessages()[0] ?? null,
					'DESCRIPTION' => '',
				],
			);

			return;
		}

		$bootstrap = $dataResult->getData();
		$this->arResult['containerId'] = 'crm-settings-hub-' . $this->randString();
		$this->arResult['bootstrap'] = [
			'settings' => $bootstrap['settings'],
			'navigation' => $bootstrap['navigation'],
		];

		$this->includeComponentTemplate();
	}
}
