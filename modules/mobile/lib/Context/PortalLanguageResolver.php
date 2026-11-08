<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Context;

final class PortalLanguageResolver
{
	public function resolve(array $site, string $fallbackLanguage): string
	{
		$language = $site['LANGUAGE_ID'] ?? null;

		return is_string($language) && $language !== '' ? $language : $fallbackLanguage;
	}
}
