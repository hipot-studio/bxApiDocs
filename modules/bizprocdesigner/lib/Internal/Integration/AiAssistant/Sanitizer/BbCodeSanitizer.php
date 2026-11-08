<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Sanitizer;

final class BbCodeSanitizer
{
	public const MAX_FRAME_CONTENT_LENGTH = 20000;

	private const FRAME_ALLOWED_TAGS = ['b', 'i', 'u', 's', 'list', '*', 'url', 'quote', 'code', 'img'];
	private const TEMPLATE_ALLOWED_TAGS = ['b', 'i', 'u', 's', 'list', '*', 'url'];
	private const TEMPLATE_ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto'];

	public static function sanitizeFrameContent(string $content): string
	{
		$content = self::truncateToSafeBoundary($content, self::MAX_FRAME_CONTENT_LENGTH);
		$content = self::sanitizeTags(
			$content,
			self::FRAME_ALLOWED_TAGS,
			['url', 'img'],
			false,
		) ?? $content;

		$content = preg_replace_callback(
			'/(\[(?:url|img)(?:\s*=[^\[\]]*)?\])(.*?)(\[\/(?:url|img)\]|$)/is',
			static fn(array $match): string => $match[1]
				. self::neutralizeDangerousScheme($match[2])
				. $match[3],
			$content,
		) ?? $content;

		return self::truncateToSafeBoundary($content, self::MAX_FRAME_CONTENT_LENGTH);
	}

	public static function sanitizeTemplateDescription(string $description, int $maxLength): ?string
	{
		$description = self::sanitizeTags(
			$description,
			self::TEMPLATE_ALLOWED_TAGS,
			['url'],
			true,
		);
		if ($description === null)
		{
			return null;
		}

		$description = preg_replace_callback(
			'/(\[url\])(.*?)(\[\/url\]|$)/is',
			static fn(array $match): string => $match[1]
				. self::sanitizeTemplateUrlBody($match[2])
				. $match[3],
			$description,
		);

		return $description === null
			? null
			: self::truncateToSafeBoundary($description, $maxLength)
		;
	}

	/**
	 * @param list<string> $allowedTags
	 * @param list<string> $urlTags
	 */
	private static function sanitizeTags(
		string $content,
		array $allowedTags,
		array $urlTags,
		bool $allowOnlyTemplateSchemes,
	): ?string
	{
		return preg_replace_callback(
			'/\[(\/?)([a-zA-Z*][a-zA-Z0-9]*)(\s*=[^\[\]]*)?\]/',
			static function (array $match) use ($allowedTags, $urlTags, $allowOnlyTemplateSchemes): string {
				$tagName = mb_strtolower($match[2]);
				$attribute = $match[3] ?? '';

				if (!in_array($tagName, $allowedTags, true))
				{
					return '&#91;' . $match[1] . $match[2] . $attribute . ']';
				}

				if (in_array($tagName, $urlTags, true) && $attribute !== '')
				{
					$attribute = $allowOnlyTemplateSchemes
						? self::sanitizeTemplateUrlAttribute($attribute)
						: self::neutralizeDangerousScheme($attribute)
					;
				}

				return '[' . $match[1] . $match[2] . $attribute . ']';
			},
			$content,
		);
	}

	private static function sanitizeTemplateUrlBody(string $body): string
	{
		return self::hasAllowedTemplateScheme(self::stripInertCharacters($body))
			? $body
			: str_replace([':', '&'], '_', $body)
		;
	}

	private static function sanitizeTemplateUrlAttribute(string $value): string
	{
		$value = self::stripInertCharacters($value);
		$prefix = str_starts_with($value, '=') ? '=' : '';
		$address = $prefix === '' ? $value : substr($value, 1);

		return $prefix . (
			self::hasAllowedTemplateScheme($address)
				? $address
				: str_replace([':', '&'], '_', $address)
		);
	}

	private static function hasAllowedTemplateScheme(string $address): bool
	{
		$head = substr($address, 0, strcspn($address, '/?#'));
		if (str_contains($head, '&'))
		{
			return false;
		}

		$colon = strpos($head, ':');

		return $colon === false
			|| in_array(strtolower(substr($head, 0, $colon)), self::TEMPLATE_ALLOWED_URL_SCHEMES, true)
		;
	}

	private static function stripInertCharacters(string $value): string
	{
		$inert = [];
		for ($code = 0x00; $code <= 0x20; $code++)
		{
			$inert[] = chr($code);
		}

		return str_replace($inert, '', $value);
	}

	private static function neutralizeDangerousScheme(string $value): string
	{
		$value = preg_replace('/[\x00-\x20]/', '', $value) ?? $value;

		return preg_replace('/(javascript|vbscript|data)\s*:/i', '$1_', $value) ?? $value;
	}

	private static function truncateToSafeBoundary(string $content, int $maxLength): string
	{
		if (mb_strlen($content) <= $maxLength)
		{
			return $content;
		}

		$content = mb_substr($content, 0, $maxLength);

		$lastOpen = mb_strrpos($content, '[');
		$lastClose = mb_strrpos($content, ']');
		if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose))
		{
			$content = mb_substr($content, 0, $lastOpen);
		}

		$lastAmpersand = mb_strrpos($content, '&');
		$lastSemicolon = mb_strrpos($content, ';');
		if ($lastAmpersand !== false && ($lastSemicolon === false || $lastAmpersand > $lastSemicolon))
		{
			$content = mb_substr($content, 0, $lastAmpersand);
		}

		return $content;
	}
}
