<?php

namespace Bitrix\Mail\Helper\Message\Parsers;

final class ReplacingUrlImagesToAbsolute
{
	public static function parse(string $body, int $imageId, string $url): string
	{
		return preg_replace_callback(
			'/src="[^"]*?\/bitrix\/tools\/crm_show_file\.php\?fileId=(\d+)[^"]*?"/i',
			function ($matches) use ($imageId, $url)
			{
				if ((int)$matches[1] === $imageId)
				{
					return 'src="' . htmlspecialcharsbx($url) . '"';
				}

				return $matches[0];
			},
			$body,
		);
	}
}