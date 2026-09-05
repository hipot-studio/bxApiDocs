<?php

namespace Bitrix\UI\Format\BBCode;

final class Whitelist
{
	private const TAGS = ['b', 'i', 'u', 's', 'url', 'img', 'video', 'list', '*', 'p'];
	private const MEDIA_TAGS = ['img', 'video'];
	private const FORBIDDEN_TAGS = ['quote', 'code', 'table', 'tr', 'td', 'th', 'spoiler', 'user', 'font', 'size', 'color', 'align'];

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
			'link', 'image', 'video', 'smileys', '|',
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
			$words = implode('|', array_map(
				static fn(string $tag): string => preg_quote($tag, '#'),
				self::FORBIDDEN_TAGS
			));

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
			$words = implode('|', array_map(
				static fn(string $tag): string => preg_quote($tag, '#'),
				array_values(array_filter(self::TAGS, static fn(string $tag): bool => $tag !== '*'))
			));

			self::$escapeBracketsPattern = "#\\[/?(?:{$words})\\b[^\\]]*\\]|\\[\\*\\]|[\\[\\]]#iu";
		}

		return self::$escapeBracketsPattern;
	}
}
