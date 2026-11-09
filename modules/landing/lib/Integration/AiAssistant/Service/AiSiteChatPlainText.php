<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

/**
 * A title of a site or a page made plain for a message of the bot.
 *
 * The title is set by any editor of the site, and the chat parses BB codes ([URL], [IMG], [SEND], [PUT], [BR])
 * and markdown links in every message, so a title must not bring markup of its own. HTML entities are decoded
 * until they stop changing, as the message may be decoded more than once on its way; then the square brackets
 * become round ones and every line break or control character becomes a space.
 */
final class AiSiteChatPlainText
{
	private function __construct()
	{
	}

	public static function title(string $title): string
	{
		do
		{
			$decoded = $title;
			$title = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		while ($title !== $decoded);

		$title = strtr($title, ['[' => '(', ']' => ')']);
		$title = preg_replace('/[\s\p{Cc}]+/u', ' ', $title) ?? preg_replace('/[\s\x00-\x1F\x7F]+/', ' ', $title);

		return trim((string)$title);
	}
}
