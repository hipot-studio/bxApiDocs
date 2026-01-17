<?php

use Bitrix\Intranet\Internal\Integration\Main\AnnualSummarySign;
use Bitrix\Intranet\Internal\Provider\AnnualSummary\FeatureProvider;
use Bitrix\Intranet\Internal\Service\AnnualSummary\Visibility;
use Bitrix\Intranet\Portal;
use Bitrix\Main\Security\Sign\BadSignatureException;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

class AnnualSummaryComponent extends \CBitrixComponent
{
	public function onPrepareComponentParams($arParams)
	{
		$signer = new AnnualSummarySign();

		if (!empty($arParams['SHORT_CODE']))
		{
			$urlData = \CBXShortUri::GetUri($arParams['SHORT_CODE']);
			if (empty($urlData))
			{
				return [];
			}
			$userAgent = \Bitrix\Main\Context::getCurrent()->getRequest()->getUserAgent();
			if ($userAgent)
			{
				\Bitrix\Main\Context::getCurrent()->getResponse()->redirectTo($urlData['URI'])->send();
				return;
			}
			preg_match("/^\/pub\/annual_summary\/(?'signedUserId'[^\/]+)\/(?'signedType'[^\/]+)\/?/", $urlData['URI'], $matches);

			$arParams['SIGNED_USER_ID'] = $matches['signedUserId'];
			$arParams['SIGNED_TYPE'] = $matches['signedType'];
		}

		try
		{
			return [
				'USER_ID' => (int)$signer->unsign($arParams['SIGNED_USER_ID']),
				'TYPE' => $signer->unsign($arParams['SIGNED_TYPE']),
			];
		}
		catch (BadSignatureException)
		{
			return [];
		}
	}
	
	public function executeComponent(): void
	{
		$userId = $this->arParams['USER_ID'];
		$type = $this->arParams['TYPE'];

		if ($userId <= 0 || empty($type) || !(new Visibility($userId))->canShow())
		{
			return;
		}

		$featureProvider = new FeatureProvider();
		$response = $featureProvider->getShared($userId, $type);
		$this->arResult = [
			'TOP_FEATURES' => [$response['feature'] ?? []],
			'FEATURES_OPTIONS' => $response['options'] ?? [],
		];
		$this->arResult['SITE_NAME'] = htmlspecialcharsbx(Portal::getInstance()->getSettings()->getTitle());
		$this->arResult = array_merge($this->arResult, $this->getMetaValues($response['feature'] ?? []));

		$this->includeComponentTemplate();
	}

	private function getMetaValues(array $feature): array
	{
		$title = '';
		$description = '';
		if (!empty($feature))
		{
			$message = $feature['message'] ?? [];

			$title = $message['title'] ?? '';
			if (!empty($message['name']))
			{
				$title = ' ' . $message['name'] ?? '';
			}
			$description = $message['description'] ?? '';
		}

		return [
			'FEATURE_TITLE' => $title,
			'FEATURE_DESCRIPTION' => $description,
		];
	}
}
