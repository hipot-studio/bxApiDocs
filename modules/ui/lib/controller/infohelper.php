<?php

namespace Bitrix\UI\Controller;

use Bitrix\Bitrix24\License;
use Bitrix\Main\Application;
use Bitrix\Bitrix24;
use Bitrix\Bitrix24\License\Market;
use Bitrix\Main\Engine;
use Bitrix\Main\Loader;
use Bitrix\UI\FeaturePromoter;

class InfoHelper extends Engine\Controller
{
	private const POPUP_PROVIDER_TEST_CODE_LIST = [];

	public function getInitParamsAction(
		string $type = FeaturePromoter\ProviderType::SLIDER,
		string $code = '',
		string $currentUrl = '',
		?string $featureId = null
	): array
	{
		$configuration = new FeaturePromoter\ProviderConfiguration($type, $code, $currentUrl, $featureId);

		return (new FeaturePromoter\ProviderFactory())->createProvider($configuration)->getRendererParameters();
	}

	public function activateDemoLicenseAction()
	{
		$result	= [
			'success' => 'N',
		];
		if (Loader::includeModule('bitrix24') && defined('BX24_HOST_NAME'))
		{
			$res = License::getCurrent()->getDemo()->activate();

			if ($res->isSuccess())
			{
				$result['success'] = 'Y';
			}
		}

		return $result;
	}

	public function getBuySubscriptionUrlAction()
	{
		$action = 'blank';
		if (Loader::includeModule('bitrix24'))
		{
			$url = Market::getDefaultBuyPath();
		}
		else
		{
			$license = Application::getInstance()->getLicense();
			$url = $license->getDomainStoreLicense() . '/key_update.php?license_key=' . $license->getHashLicenseKey() . '&tobasket=y&action=b24subscr';
		}

		return [
			'url' => $url,
			'action' => $action,
		];
	}

	public function activateTrialFeatureAction(string $featureId)
	{
		$result	= [
			'success' => 'N',
		];

		if (
			Loader::includeModule('bitrix24')
			&& method_exists(Bitrix24\Feature::class, 'trialFeature')
		)
		{
			$trialable = Bitrix24\Feature::isFeatureTrialable($featureId);

			if ($trialable)
			{
				$result['success'] = Bitrix24\Feature::trialFeature($featureId) ? 'Y' : 'N';
			}
			else
			{
				$result['error'] = 'IS_NOT_TRIALABLE';
			}
		}

		return $result;
	}

	public function showLimitSliderAction(): bool
	{
		return true;
	}
}
