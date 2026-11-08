<?php

namespace Bitrix\Intranet\Internal\Service\Portal;

final class FirstPageUrlPolicy
{
	public static function isAllowedAsFirstPage(mixed $url): bool
	{
		if (!is_string($url))
		{
			return false;
		}

		$parts = parse_url($url);
		if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || str_starts_with($url, '//'))
		{
			return false;
		}

		return strcasecmp($parts['path'] ?? '', '/bitrix/services/main/ajax.php') !== 0;
	}
}
