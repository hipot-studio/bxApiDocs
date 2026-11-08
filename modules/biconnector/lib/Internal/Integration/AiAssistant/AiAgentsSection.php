<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Web\Uri;

final class AiAgentsSection
{
	private static array $availabilityByUserId = [];

	public static function isAvailable(): bool
	{
		$userId = (int)CurrentUser::get()->getId();
		if (!isset(self::$availabilityByUserId[$userId]))
		{
			self::$availabilityByUserId[$userId] =
				Feature::isAiAgentsSectionEnabled()
				&& BitrixGptChat::isAvailable()
			;
		}

		return self::$availabilityByUserId[$userId];
	}

	public static function getPath(): string
	{
		return rtrim(SITE_DIR, '/') . '/bi/ai-agents/';
	}

	public static function getUrl(string $source): string
	{
		$uri = new Uri(self::getPath());
		$uri->addParams(['from' => AiAgentsEntrySource::normalize($source)]);

		return $uri->getUri();
	}
}
