<?php

namespace Bitrix\UI\FeaturePromoter;

use Bitrix\Main\Loader;
use Bitrix\UI\Helpdesk;
use Bitrix\Bitrix24;

class Slider implements FeaturePromoterProvider
{
	private const PATH_HELPDESK = '/widget2/show/code/';

	public function __construct(private string $currentUrl = '')
	{
	}

	public function getRendererParameters(): array
	{
		$requestHelpdesk = new Helpdesk\Request(self::PATH_HELPDESK, [
			'url' => $this->currentUrl,
			'featurePromoterVersion' => 2,
		]);

		return [
			'frameUrlTemplate' => $requestHelpdesk->getPreparedUrl(),
			'trialableFeatureList' => $this->getTrialableFeatureList(),
			'availableDomainList' => $requestHelpdesk->getUrl()->getDomain()->getList(),
		];
	}

	private function getTrialableFeatureList(): array
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Bitrix24\Feature::getTrialableFeatureList();
		}

		return [];
	}
}