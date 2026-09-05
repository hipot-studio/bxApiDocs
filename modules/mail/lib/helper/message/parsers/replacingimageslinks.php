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
		// the whitespace runs are possessive: neither 'aid:' nor the closing quote is a space,
		// so giving a space back can never help the match, and a long run of them would otherwise
		// exhaust pcre.backtrack_limit for every attachment of the message
		$result = preg_replace(
			sprintf('/("|\')\s*+aid:%u\s*+\1/i', $imageId),
			sprintf('\1%s\1', $url),
			$body,
		);

		// preg_replace returns null on a PCRE error, e.g. the backtrack limit on a long body
		return $result ?? $body;
	}
}