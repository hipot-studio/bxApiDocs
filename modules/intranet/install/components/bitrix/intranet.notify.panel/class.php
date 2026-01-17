<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Intranet\Internal\Provider\AnnualSummary\FeatureProvider;
use Bitrix\Intranet\Internal\Service\AnnualSummary\Visibility;
use Bitrix\Intranet\License\Notification;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ModuleManager;

class IntranetNotifyPanelComponent extends \CBitrixComponent implements Controllerable
{
	public function executeComponent()
	{
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			return;
		}

		$this->includeComponentTemplate();
	}

	public function onPrepareComponentParams($arParams): array
	{
		$this->arResult['config'] = [
			'isAdmin' => CurrentUser::get()->isAdmin(),
			'notify' => $this->getNotifyConfiguration(),
		];

		if ((new Visibility(CurrentUser::get()->getId()))->canForceShow())
		{
			$this->arResult['annualSummary'] = $this->getAnnualSummaryData();
		}

		return parent::onPrepareComponentParams($arParams);
	}

	public function configureActions(): array
	{
		return [];
	}

	private function getNotifyConfiguration(): array
	{
		$providersClasses = $this->getNotificationProviders();

		foreach ($providersClasses as $providerClass)
		{
			$provider = new $providerClass();

			if ($provider->isAvailable() && $provider->checkNeedToShow())
			{
				return $provider->getConfiguration();
			}
		}

		return [];
	}

	/**
	 * @return Notification\NotificationProvider[]
	 */
	private function getNotificationProviders(): array
	{
		return [
			Notification\Popup::class,
			Notification\Panel::class,
		];
	}

	public function setLicenseNotifyConfigAction(string $type): void
	{
		(new Notification\Popup())->saveShowConfiguration($type);
	}

	private function getAnnualSummaryData(): array
	{
		$annualSummary = new FeatureProvider();
		$annualSummaryData = $annualSummary->getTop();

		return [
			'features' => $annualSummaryData['topFeatures'],
			'options' => $annualSummaryData['options'],
		];
	}
}
