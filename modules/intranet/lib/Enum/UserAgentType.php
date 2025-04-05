<?php

namespace Bitrix\Intranet\Enum;

use Bitrix\Main\HttpRequest;

enum UserAgentType
{
	case MOBILE_APP;
	case DESKTOP;
	case BROWSER;
	case UNKNOWN;

	public static function fromRequest(HttpRequest $request): static
	{
		$userAgent = $request->getUserAgent();
		if (empty($userAgent))
		{
			return static::UNKNOWN;
		}

		return match (true)
		{
			str_contains($userAgent, 'BitrixDesktop') => static::DESKTOP,
			str_contains($userAgent, 'BitrixMobile') => static::MOBILE_APP,
			default => static::BROWSER,
		};
	}
}
