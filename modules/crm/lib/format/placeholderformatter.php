<?php

namespace Bitrix\Crm\Format;

use Bitrix\Crm\Integration\UI\EntitySelector\PlaceholderProvider;

final class PlaceholderFormatter
{
	private static array $placeholdersCache = [];

	public static function convertToDisplayFormat(int $entityTypeId, string $externalInput): string
	{
		if (!self::hasPlaceholders($externalInput))
		{
			return $externalInput;
		}

		$placeholders = self::getPlaceholders($entityTypeId);
		$replaceMap = [];

		foreach ($placeholders as $code => $placeholder)
		{
			$replaceMap["{{$code}}"] = "{{$placeholder}}";
		}

		return strtr($externalInput, $replaceMap);
	}

	public static function convertToExternalFormat(int $entityTypeId, string $displayInput): string
	{
		if (!self::hasPlaceholders($displayInput))
		{
			return $displayInput;
		}

		$placeholders = self::getPlaceholders($entityTypeId);
		$replaceMap = [];

		foreach ($placeholders as $code => $placeholder)
		{
			$replaceMap["{{$placeholder}}"] = "{{$code}}";
		}

		return strtr($displayInput, $replaceMap);
	}

	/**
	 * Converts display-format placeholders to BBCode format.
	 * {Deal: ID} → [placeholder code=DealId]Deal: ID[/placeholder]
	 */
	public static function convertDisplayToBBCodeFormat(int $entityTypeId, string $displayInput): string
	{
		if (!self::hasPlaceholders($displayInput))
		{
			return $displayInput;
		}

		$placeholders = self::getPlaceholders($entityTypeId);
		$replaceMap = [];

		foreach ($placeholders as $code => $placeholder)
		{
			$replaceMap["{{$placeholder}}"] = "[placeholder code={$code}]{$placeholder}[/placeholder]";
		}

		return strtr($displayInput, $replaceMap);
	}

	/**
	 * Converts BBCode placeholder tags to external format for document generator.
	 * [placeholder code=DealId]Deal: ID[/placeholder] → {DealId}
	 *
	 * Mandatory parts mirror the frontend PlaceholderService contract:
	 * a non-empty `code=` attribute and a caption that is not empty or
	 * whitespace-only. Tags missing either are not considered placeholders
	 * and are left unchanged.
	 */
	public static function convertBBCodeToExternalFormat(string $text): string
	{
		// A quick check to avoid the overhead of regex when no placeholders are present.
		if (!str_contains($text, '[placeholder'))
		{
			return $text;
		}

		// The negative lookahead `(?!\s*\[/placeholder\])` after the opening `]`
		// rejects both empty and whitespace-only captions in one step.
		return preg_replace_callback(
			'/\[placeholder\s+[^\]]*?\bcode=(?:"([^"]+)"|([^\s"\]]+))[^\]]*\](?!\s*\[\/placeholder\]).+?\[\/placeholder\]/s',
			static fn(array $matches) => '{' . ($matches[1] !== '' ? $matches[1] : $matches[2]) . '}',
			$text,
		);
	}

	/**
	 * Checking for placeholders before calling getPlaceholders() avoids expensive memory operations when no placeholders are present.
	 */
	public static function hasPlaceholders(string $input): bool
	{
		if (trim($input) === '')
		{
			return false;
		}

		$opened = false;
		$len = strlen($input);
		for ($i = 0; $i < $len; $i++)
		{
			$ch = $input[$i];
			if ($ch === '{')
			{
				$opened = true;
			}
			elseif ($ch === '}' && $opened)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int $entityTypeId
	 * @return array<string, string> [externalFormat => displayFormat]
	 */
	private static function getPlaceholders(int $entityTypeId): array
	{
		if (isset(self::$placeholdersCache[$entityTypeId]))
		{
			return self::$placeholdersCache[$entityTypeId];
		}

		$result = [];
		$placeholderProvider = new PlaceholderProvider(['entityTypeId' => $entityTypeId]);

		foreach ($placeholderProvider->getItems([]) as $item)
		{
			$result = array_merge($result, self::getPlaceholdersByItem($item));
		}

		self::$placeholdersCache[$entityTypeId] = $result;

		return $result;
	}

	private static function getPlaceholdersByItem($item): array
	{
		$result = [];

		foreach ($item->getChildren() as $child)
		{
			if ($child->getChildren())
			{
				$result = array_merge($result, self::getPlaceholdersByItem($child));
			}

			$result[$child->getId()] = $child->getCustomData()->get('text');
		}

		return $result;
	}
}
