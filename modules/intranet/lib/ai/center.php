<?php

namespace Bitrix\Intranet\AI;

use Bitrix\Bitrix24\Feature;
use Bitrix\Bitrix24\Integration\AssistantApp;
use Bitrix\Faceid\AgreementTable;
use Bitrix\FaceId\FaceId;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\AppTable;

class Center
{
	public static function getAssistantApp()
	{
		$app = null;
		if (Loader::includeModule("bitrix24"))
		{
			if (Feature::isFeatureEnabled("ai_assistant"))
			{
				$app = AssistantApp::getInfo();
			}
		}
		else if (Loader::includeModule("rest"))
		{
			$app = AppTable::getByClientId("bitrix.assistant");
		}

		return $app;
	}

	public static function getAssistants()
	{
		if (LANGUAGE_ID !== "ru")
		{
			return [];
		}

		$app = static::getAssistantApp();
		if (!Loader::includeModule("rest") || ($app !== null && !\CRestUtil::checkAppAccess("bitrix.assistant")))
		{
			return [];
		}

		$licensePrefix = null;
		$featureEnabled = true;
		if (Loader::includeModule("bitrix24"))
		{
			$licensePrefix = \CBitrix24::getLicensePrefix();
			$featureEnabled = Feature::isFeatureEnabled("ai_assistant");
		}

		$items = [];
		$selected = is_array($app) && $app["ACTIVE"] === "Y";

		if ($licensePrefix !== "ua")
		{
			$items[] = [
				"id" => "alice",
				"name" => Loc::getMessage("INTRANET_AI_ASSISTANT_ALICE"),
				"iconClass" => "intranet-ai-center-icon intranet-ai-center-icon-alice",
				"iconColor" => "#9426ff",
				"selected" => $selected,
				"data" => [
					'featureEnabled' => $featureEnabled,
				],
			];
		}

		$items[] = [
			"id" => "google",
			"name" => Loc::getMessage("INTRANET_AI_ASSISTANT_GOOGLE"),
			"iconClass" => "intranet-ai-center-icon intranet-ai-center-icon-google",
			"iconColor" => "#ea4335",
			"selected" => $selected,
			"data" => [
				'featureEnabled' => $featureEnabled,
			],
		];

		return $items;
	}
}
