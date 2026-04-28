<?php

namespace Bitrix\Landing\Transfer\Import;

use Bitrix\Landing\Transfer\Producer;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\Marketplace;
use Bitrix\Landing\Transfer\AppConfiguration;

Loc::loadMessages(__FILE__);

/**
 * Import site from rest
 */
class Site
{
	/**
	 * Returns export url for the site.
	 * @param string $type Site type.
	 * @return string
	 */
	public static function getUrl(string $type): string
	{
		if (!\Bitrix\Main\Loader::includeModule('rest'))
		{
			return '';
		}

		return Marketplace\Url::getConfigurationImportManifestUrl(
			AppConfiguration::PREFIX_CODE . strtolower($type)
		);
	}

	/**
	 * Returns prepare manifest settings for import.
	 * @param Event $event Event instance.
	 * @return array|null
	 */
	public static function getInitManifest(Event $event): ?array
	{
		return [
			'NEXT' => false,
		];
	}

	/**
	 * Process one export step.
	 * @param Event $event Event instance.
	 * @return array|null
	 */
	public static function nextStep(Event $event): ?array
	{
		return (new Producer($event))->make();
	}

	/**
	 * Final step.
	 * @param Event $event
	 * @return array
	 */
	public static function onFinish(Event $event): array
	{
		return (new Producer($event))->finishMake();
	}
}