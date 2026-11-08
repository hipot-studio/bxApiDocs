<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Provider\PermissionMatrixProvider;
use Bitrix\Bizproc\Internal\Service\Feature\BpDesignerFeature;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class BizprocConfigPermissionsComponent extends CBitrixComponent
{
	private const CONTAINER_ID = 'bizproc-config-permissions-container';

	public function executeComponent(): void
	{
		global $APPLICATION;
		/** @var CMain $APPLICATION */

		if (!$this->includeModules())
		{
			$this->showError(ErrorMessage::MODULE_NOT_INSTALLED->get());

			return;
		}

		$feature = $this->getFeature();
		if (!$feature->isAvailable())
		{
			$this->showError($feature->makeUnavailableByTariffError()->getMessage());

			return;
		}

		$provider = new PermissionMatrixProvider();
		if (!$provider->canConfigure())
		{
			$this->showError(Loc::getMessage('BIZPROC_CONFIG_PERMISSIONS_ACCESS_DENIED'));

			return;
		}

		if (($this->arParams['SET_TITLE'] ?? 'Y') === 'Y')
		{
			$APPLICATION->SetTitle(Loc::getMessage('BIZPROC_CONFIG_PERMISSIONS_TITLE'));
		}

		$this->arResult['OPTIONS'] = $provider->getOptions($this->getName(), self::CONTAINER_ID);

		$this->includeComponentTemplate();
	}

	protected function getFeature(): BpDesignerFeature
	{
		return new BpDesignerFeature();
	}

	private function includeModules(): bool
	{
		return Loader::includeModule('bizproc') && Loader::includeModule('ui');
	}

	private function showError(string $message): void
	{
		\Bitrix\UI\Toolbar\Facade\Toolbar::deleteFavoriteStar();

		global $APPLICATION;
		/** @var CMain $APPLICATION */

		$APPLICATION->IncludeComponent(
			'bitrix:ui.info.error',
			'',
			['TITLE' => $message],
		);
	}
}
