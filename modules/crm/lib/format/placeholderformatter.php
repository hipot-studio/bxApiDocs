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

	public static function escapeUnknownPlaceholdersInExternal(int $entityTypeId, string $externalInput): string
	{
		if (!self::hasPlaceholders($externalInput))
		{
			return $externalInput;
		}

		$placeholders = self::getPlaceholders($entityTypeId);

		$len = strlen($externalInput);
		$keep = array_fill(0, $len, false);

		// Mark known placeholders only (full "{externalPlaceholder}" tokens). All other braces will be escaped.
		if (!empty($placeholders))
		{
			foreach (array_keys($placeholders) as $externalPlaceholder)
			{
				$needle = '{' . $externalPlaceholder . '}';
				$needleLen = strlen($needle);
				$offset = 0;
				while (($idx = strpos($externalInput, $needle, $offset)) !== false)
				{
					$end = $idx + $needleLen - 1; // inclusive end index of the token
					for ($p = $idx; $p <= $end; $p++)
					{
						$keep[$p] = true;
					}
					$offset = $idx + 1; // continue search allowing overlaps
				}
			}
		}

		// Build resulting string: escape every brace not marked to keep
		$out = '';
		for ($i = 0; $i < $len; $i++)
		{
			$ch = $externalInput[$i];
			if ($keep[$i])
			{
				$out .= $ch;
			}
			elseif ($ch === '{')
			{
				$out .= '&#123;';
			}
			elseif ($ch === '}')
			{
				$out .= '&#125;';
			}
			else
			{
				$out .= $ch;
			}
		}

		return $out;
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

	/**
	 * Checking for placeholders before calling getPlaceholders() avoids expensive memory operations when no placeholders are present.
	 */
	private static function hasPlaceholders(string $input): bool
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
}
