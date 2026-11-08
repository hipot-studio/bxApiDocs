<?php

namespace Bitrix\UI\Format\BBCode;

final class Whitelist
{
	private const TAGS = ['b', 'i', 'u', 's', 'url', 'img', 'video', 'list', '*', 'p'];
	private const MEDIA_TAGS = ['img', 'video'];
	/** Block half of the forbidden tags: the rest of them are inline and must not add a space. */
	private const FORBIDDEN_BLOCK_TAGS = ['quote', 'code', 'table', 'tr', 'td', 'th', 'spoiler', 'align'];
	private const FORBIDDEN_INLINE_TAGS = ['user', 'font', 'size', 'color'];

	/** Tail of a real tag: an optional `=value` or `attr=value` up to the closing bracket. */
	private const TAG_TAIL_PATTERN = '(?:\\s*=[^\\]]*|(?:\\s+[a-z0-9_-]+\\s*=[^\\]]*)?)\\s*\\]';

	private static ?string $stripForbiddenPattern = null;
	private static ?string $escapeBracketsPattern = null;

	public static function getTags(): array
	{
		return self::TAGS;
	}

	public static function getMediaTags(): array
	{
		return self::MEDIA_TAGS;
	}

	public static function getForbiddenBlockTags(): array
	{
		return self::FORBIDDEN_BLOCK_TAGS;
	}

	/**
	 * Matches a single tag of the given list in one of the real markup forms:
	 * `[tag]`, `[tag=value]`, `[tag attr=value]` or the closing `[/tag]`.
	 * Free text after the tag name is not markup, so `[p.s.]` or `[b 2024]` stay untouched.
	 * The `[*]` list marker is handled apart by the callers.
	 * Returns a pattern without delimiters, the enclosing pattern must add the `iu` modifiers.
	 */
	public static function getTagPattern(array $tags): string
	{
		return '\\[/?(?:' . self::getTagAlternation($tags) . ')' . self::TAG_TAIL_PATTERN;
	}

	/**
	 * Matches a whole `[tag]...[/tag]` block of the given list, opening tag in the same strict form
	 * as getTagPattern(). The content is matched lazily, so the enclosing pattern must add the `s`
	 * modifier on top of `iu`.
	 */
	public static function getPairedTagPattern(array $tags): string
	{
		return '\\[(?P<pairedTag>' . self::getTagAlternation($tags) . ')' . self::TAG_TAIL_PATTERN
			. '.*?\\[/(?P=pairedTag)\\s*\\]'
		;
	}

	private static function getTagAlternation(array $tags): string
	{
		return implode('|', array_map(
			static fn(string $tag): string => preg_quote($tag, '#'),
			array_values(array_filter($tags, static fn(string $tag): bool => $tag !== '*'))
		));
	}

	private static function getForbiddenTags(): array
	{
		return array_merge(self::FORBIDDEN_BLOCK_TAGS, self::FORBIDDEN_INLINE_TAGS);
	}

	public static function getParserAllow(): array
	{
		return [
			'HTML' => 'N',
			'NL2BR' => 'Y',
			'BIU' => 'Y',
			'ANCHOR' => 'Y',
			'IMG' => 'Y',
			'VIDEO' => 'Y',
			'LIST' => 'Y',
			'P' => 'Y',
			'SMILES' => 'Y',
		];
	}

	public static function getSanitizerTags(): array
	{
		return [
			'a' => ['href', 'title', 'target', 'rel', 'class', 'name'],
			'b' => ['class'],
			'i' => ['class'],
			'u' => ['class'],
			's' => ['class'],
			'br' => [],
			'p' => ['class', 'style'],
			'ul' => ['class'],
			'ol' => ['class', 'type'],
			'li' => ['class'],
			'img' => ['src', 'alt', 'title', 'width', 'height', 'border', 'class', 'style'],
			'iframe' => ['src', 'width', 'height', 'frameborder', 'allowfullscreen', 'style', 'class'],
		];
	}

	public static function getToolbarTools(): array
	{
		return [
			'bold', 'italic', 'underline', 'strikethrough', '|',
			'numbered-list', 'bulleted-list', '|',
			'link', 'image', 'video', '|',
			'clear-format',
		];
	}

	public static function normalize(string $value): string
	{
		$value = self::stripForbiddenBbTags($value);
		$value = self::escapeDanglingBrackets($value);

		return $value;
	}

	public static function stripForbiddenBbTags(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		return (string)preg_replace(self::getStripForbiddenPattern(), '', $value);
	}

	private static function getStripForbiddenPattern(): string
	{
		if (self::$stripForbiddenPattern === null)
		{
			$words = self::getTagAlternation(self::getForbiddenTags());

			self::$stripForbiddenPattern = "#\\[/?(?:{$words})\\b[^\\]]*\\]#iu";
		}

		return self::$stripForbiddenPattern;
	}

	public static function escapeDanglingBrackets(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		return (string)preg_replace_callback(
			self::getEscapeBracketsPattern(),
			static function (array $matches): string {
				return match ($matches[0])
				{
					'[' => '&#91;',
					']' => '&#93;',
					default => $matches[0],
				};
			},
			$value
		);
	}

	private static function getEscapeBracketsPattern(): string
	{
		if (self::$escapeBracketsPattern === null)
		{
			self::$escapeBracketsPattern = '#' . self::getTagPattern(self::TAGS) . "|\\[\\*\\]|[\\[\\]]#iu";
		}

		return self::$escapeBracketsPattern;
	}
}
