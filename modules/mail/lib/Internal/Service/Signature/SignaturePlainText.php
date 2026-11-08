<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature;

/**
 * Plain-text preview of a signature for lists and menus.
 *
 * A signature is authored as HTML, and its line breaks are the only word boundary it has: the name
 * sits on one line and the text on the next with nothing but a <br> between them. Stripping the
 * markup without putting a separator back glues them into one word, so every place that shows a
 * preview has to replace the breaks first - and every place that forgot showed "NameThe text".
 */
final class SignaturePlainText
{
	private const BOUNDARY_PATTERN = '~</?(?:br|p|div|li|tr|h[1-6])\b[^>]*>~i';

	/**
	 * Single line of plain text, whitespace collapsed, optionally cut to the given length.
	 */
	public static function fromHtml(string $signature, ?int $maxLength = null): string
	{
		$text = (string)preg_replace(self::BOUNDARY_PATTERN, ' ', $signature);
		$text = html_entity_decode(strip_tags($text), ENT_COMPAT, 'UTF-8');
		$text = (string)preg_replace('#[\s\p{Z}]+#u', ' ', $text);
		$text = trim($text);

		if ($maxLength !== null && $maxLength > 0)
		{
			$text = trim(mb_substr($text, 0, $maxLength));
		}

		return $text;
	}
}
