<?php

namespace Bitrix\Intranet\Internal\Service\Portal;

use Bitrix\Main\Web\Uri;

final class PortalFirstPageUrlNormalizer
{
	public function normalize(string $url): string
	{
		$uri = new Uri($url);
		if (str_contains($uri->getPath(), '#USER_ID#') || str_starts_with($url, '//'))
		{
			return $url;
		}

		if ($uri->getScheme() !== '' || $uri->getHost() !== '')
		{
			return $url;
		}

		$replacementCount = 0;
		$normalizedPath = preg_replace(
			'~^((?:/[^/]+)*/company/personal/user/)\d+(/tasks(?:/.*)?)$~i',
			'$1#USER_ID#$2',
			$uri->getPath(),
			1,
			$replacementCount,
		);

		if ($replacementCount === 0 || $normalizedPath === null)
		{
			return $url;
		}

		return $uri->setPath($normalizedPath)->getUri();
	}
}
