<?php

namespace Bitrix\Sign\Service\Integration\Im;

/**
 * Turns text written by a user into a safe BB-code representation once, on the server side.
 *
 * Both messenger clients render message text as BB-code, and both convert Markdown into
 * BB-code before that (im/install/js/im/v2/lib/parser/src/markdown, immobile/install/mobileapp/
 * immobile/extensions/im/messenger/lib/parser/src/markdown). So raw user input could forge a
 * mention or a link and could turn its own markup into card formatting. Escaping with HTML
 * entities is not an option: the web parser decodes them after BB-code is parsed, the mobile
 * one before that. What works for both is an invisible separator placed inside every construct
 * the parsers look for: their patterns require the significant characters to sit next to each
 * other, and the separator itself is not rendered.
 *
 * Two separators are needed. U+200B is not whitespace for a JS regular expression, so it breaks
 * the patterns that expect a tag name, a bracket, a space or a line start at a fixed position.
 * Emphasis (*text*, _text_) is the opposite case: its pattern requires the content NOT to start
 * with whitespace, so it is broken by U+FEFF, the only zero-width character JS `\s` matches.
 *
 * The web parser also has formatting commands of its own ('/me', '/loud') that it reads at the
 * very start of the text, and they are neutralized the same way.
 *
 * URLs are set aside before the separators are inserted and come back as link tags, so no
 * invisible character ever ends up inside a link.
 *
 * Clients must not escape the result again: the link tags added here are meant to be rendered.
 */
class MessageTextSanitizer
{
	private const ZERO_WIDTH_SPACE = "\u{200B}";

	/**
	 * The only zero-width character JS `\s` matches, which is what breaks the emphasis rules.
	 */
	private const EMPHASIS_SEPARATOR = "\u{FEFF}";

	/**
	 * Only http(s) links are recognized, so no other scheme can reach a link tag.
	 * Brackets, angle brackets, quotes and any invisible character are excluded from the match:
	 * that keeps the tag and its parameter unbreakable no matter what the user typed.
	 */
	private const URL_PATTERN = '~https?://[^\s\pZ\pC\[\]<>"\x27`{}|\^\\\\]+~iu';

	private const URL_TAIL_PUNCTUATION = '.,;:!?';

	/**
	 * Entities the mobile parser decodes into '[' or '<' before it looks for tags, plus the ones
	 * decoding into '&' itself: the mobile parser decodes entities in a single pass, so without
	 * them '&#38;#91;' would survive as a literal '&#91;' and reach the native renderer.
	 *
	 * A decimal entity may carry a tail of hex letters ('&#91A;'): the mobile parser accepts them
	 * into the entity and then reads the value with parseInt(..., 10), which stops at the first
	 * letter and still yields '['. The hexadecimal form needs no such tail, there the whole
	 * sequence is read as one number.
	 */
	private const MARKUP_ENTITY_PATTERN =
		'~&(?=(?:#0*(?:91|60|38)(?:[a-f][\da-f]*)?|#x0*(?:5b|3c|26)|lt|amp);)~i';

	/**
	 * '<' of an HTML formatting tag (the mobile parser turns those into BB-code) or of a
	 * Markdown autolink.
	 */
	private const ANGLE_BRACKET_PATTERN = '~<(?=(?:/?(?:u|b|strong|i|em|s|del|strike)\b|https?://|[\w%+.-]+@))~i';

	/**
	 * Markdown link and image: both converters give up unless the text contains ']('.
	 */
	private const MARKDOWN_LINK_PATTERN = '~](?=\()~';

	/**
	 * Every character JS `\s` matches inside a line, written out as raw UTF-8 bytes: both
	 * converters test their block markers with `\s` or with `String.trim()`, so a space and a tab
	 * are not the whole class (U+00A0 alone turns '#' into a heading). Line breaks are left out:
	 * the pattern below works line by line.
	 */
	private const LINE_WHITESPACE = '(?:[ \t\x0B\f]|\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]'
		. '|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF)';

	/**
	 * Block constructs both converters recognize at the start of a line: ATX heading, list item,
	 * blockquote, thematic break or setext underline, indented code block. The separator goes in
	 * front of the whole line, because a marker needs both its own position and the space after
	 * it, and the two cannot be broken by one separator from the inside.
	 *
	 * Leading whitespace of the line is part of every marker alternative but the indented code
	 * one: the converters either test the line trimmed, or get it trimmed before they see it
	 * (the web parser trims the whole message text between the two passes), so whitespace in
	 * front of a marker is no protection. Indented code is left out on purpose: there the four
	 * spaces are the marker itself, and both converters read them at the line start only.
	 *
	 * A bullet marker is matched with nothing after it on purpose: the emphasis separator inserted
	 * later next to '*' is whitespace for the mobile list rule, so the protection itself would
	 * otherwise turn '*text*' into a list item.
	 */
	private const BLOCK_CONSTRUCT_LINE_PATTERN = '~^(?=(?:'
		. self::LINE_WHITESPACE . '*(?:'
			. '#{1,6}' . self::LINE_WHITESPACE
			. '|[*+-]|\d+\.' . self::LINE_WHITESPACE
			. '|>'
			. '|[-=*_](?:[-=*_]|' . self::LINE_WHITESPACE . ')*$'
		. ')'
		. '|(?: {4}|\t)'
		. '))~m';

	/**
	 * The web parser has its own formatting commands, '/me' and '/loud', recognized by
	 * String.startsWith() on the whole message text, after the text was trimmed
	 * (im/install/js/im/v2/lib/parser/src/functions/slash-command.js, called from
	 * im/install/js/im/v2/lib/parser/src/classes/decoder.js). Leading whitespace is therefore no
	 * protection, and the command itself carries no marker to break from the outside: the
	 * separator goes between the slash and the command name, which the handler reads as one
	 * string. The handler is case-sensitive and does not require a space after the name, so
	 * neither does this pattern.
	 */
	private const SLASH_COMMAND_PATTERN = '~^(?:' . self::LINE_WHITESPACE . '|\n)*/(?=me|loud)~';

	/**
	 * The legacy quote of both clients: a run of exactly 54 dashes opens it and another one closes
	 * it (ParserQuote.decodeQuote in im/install/js/im/v2/lib/parser/src/functions/quote.js and its
	 * mobile counterpart). This marker is not tied to the start of a line, so the separator goes
	 * inside the run: shortening it by one character is enough, and a longer run gets broken
	 * every 53 characters.
	 */
	private const LEGACY_QUOTE_SEPARATOR_PATTERN = '~-{53}(?=-)~';

	public function toSafeBBCode(?string $text): string
	{
		if ($text === null || $text === '')
		{
			return '';
		}

		$text = $this->normalizeLineEndings($text);
		[$text, $urls] = $this->setUrlsAside($text);
		$text = $this->defangMarkup($text);

		return $this->restoreUrlsAsLinks($text, $urls);
	}

	private function normalizeLineEndings(string $text): string
	{
		return str_replace(["\r\n", "\r"], "\n", $text);
	}

	/**
	 * All patterns below match bytes, non-ASCII characters included (see LINE_WHITESPACE), and are
	 * intentionally used without the `u` modifier: they stay correct byte by byte on UTF-8 and
	 * cannot fail on malformed input, where `u` would make the match return null and let the text
	 * through unprotected.
	 */
	private function defangMarkup(string $text): string
	{
		$text = $this->insertSeparator(self::BLOCK_CONSTRUCT_LINE_PATTERN, self::ZERO_WIDTH_SPACE, $text);
		$text = $this->insertSeparator(self::SLASH_COMMAND_PATTERN, self::ZERO_WIDTH_SPACE, $text);
		$text = $this->insertSeparator(self::LEGACY_QUOTE_SEPARATOR_PATTERN, self::ZERO_WIDTH_SPACE, $text);

		// BB-code tags of both clients
		$text = str_replace('[', '[' . self::ZERO_WIDTH_SPACE, $text);
		$text = $this->insertSeparator(self::MARKUP_ENTITY_PATTERN, self::ZERO_WIDTH_SPACE, $text);
		$text = $this->insertSeparator(self::ANGLE_BRACKET_PATTERN, self::ZERO_WIDTH_SPACE, $text);

		$text = $this->insertSeparator(self::MARKDOWN_LINK_PATTERN, self::ZERO_WIDTH_SPACE, $text);
		// inline code and fenced code block
		$text = $this->insertSeparator('~`~', self::ZERO_WIDTH_SPACE, $text);
		// strikethrough and tilde-fenced code block
		$text = $this->insertSeparator('/~/', self::ZERO_WIDTH_SPACE, $text);
		// table row
		$text = $this->insertSeparator('~\|~', self::ZERO_WIDTH_SPACE, $text);

		// emphasis, in every width: ***text***, **text**, *text* and the underscore variants
		$text = $this->insertSeparator('~\*~', self::EMPHASIS_SEPARATOR, $text);

		return $this->insertSeparator('~_~', self::EMPHASIS_SEPARATOR, $text);
	}

	private function insertSeparator(string $pattern, string $separator, string $text): string
	{
		return preg_replace($pattern, '${0}' . $separator, $text) ?? $text;
	}

	/**
	 * @return array{0: string, 1: array<string, string>} text with URLs replaced by tokens and
	 *   the token map. A token carries a per-call nonce, so a user cannot type one and have a URL
	 *   substituted into their own text.
	 */
	private function setUrlsAside(string $text): array
	{
		$urls = [];
		$nonce = bin2hex(random_bytes(8));

		$result = preg_replace_callback(
			self::URL_PATTERN,
			function (array $matches) use (&$urls, $nonce): string {
				// a link tag is returned as is, so an address carrying a markup entity has to stay
				// plain text and go through the neutralization below
				if (preg_match(self::MARKUP_ENTITY_PATTERN, $matches[0]) === 1)
				{
					return $matches[0];
				}

				$url = $this->trimUrlTail($matches[0]);

				if (!preg_match('~^https?://.~i', $url))
				{
					return $matches[0];
				}

				// letters and digits only, so no separator is ever inserted into a token
				$token = 'sign' . $nonce . 'url' . count($urls) . 'end';
				$urls[$token] = $url;

				return $token . substr($matches[0], strlen($url));
			},
			$text,
		);

		return $result === null ? [$text, []] : [$result, $urls];
	}

	/**
	 * The parameterless form is the one the server builds for any link a person writes in a chat
	 * (\Bitrix\Im\Text::parse collapses [url=X]X[/url] into it anyway), so the card receives exactly
	 * the same shape as an ordinary message and needs nothing special from the clients.
	 *
	 * @param array<string, string> $urls
	 */
	private function restoreUrlsAsLinks(string $text, array $urls): string
	{
		foreach ($urls as $token => $url)
		{
			$text = str_replace($token, '[url]' . $url . '[/url]', $text);
		}

		return $text;
	}

	/**
	 * Sentence punctuation and an unbalanced closing parenthesis belong to the text, not to the link.
	 */
	private function trimUrlTail(string $url): string
	{
		while ($url !== '')
		{
			$lastChar = $url[strlen($url) - 1];

			if (str_contains(self::URL_TAIL_PUNCTUATION, $lastChar))
			{
				$url = substr($url, 0, -1);
				continue;
			}

			if ($lastChar === ')' && substr_count($url, ')') > substr_count($url, '('))
			{
				$url = substr($url, 0, -1);
				continue;
			}

			break;
		}

		return $url;
	}
}
