<?php

namespace Bitrix\Mail\Helper\Message\Parsers;

final class ReplacingImagesLinks
{
	/**
	 * Replace pictures with the specified id with links to images
	 *
	 * @param string $body
	 * @param int $imageId
	 * @param string $url
	 * @return string
	 */
	public static function parse(string $body, int $imageId, string $url): string
	{
		return preg_replace(
			sprintf('/("|\')\s*aid:%u\s*\1/i', $imageId),
			sprintf('\1%s\1', $url),
			$body,
		);
	}
}