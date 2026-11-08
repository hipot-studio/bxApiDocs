<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

namespace Bitrix\Main\Web\Http;

class Etag
{
	/**
	 * Wraps the value in the double quotes required by the entity-tag syntax of RFC 7232, section 2.3;
	 * an already quoted value does not get a second pair.
	 * The value itself is not validated: the caller must pass characters allowed in an entity-tag,
	 * because a quote, CR, LF or a control character would corrupt the header.
	 * The argument is a bare entity tag, not an If-None-Match list item: the caller strips the W/ prefix itself.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function quote(string $value): string
	{
		return '"' . trim($value, '"') . '"';
	}

	/**
	 * Checks the If-None-Match header value against the entity tag of the resource.
	 * Weak comparison by RFC 7232, section 2.3.2: the W/ prefix is not significant.
	 * Quotes are part of the entity-tag syntax (section 2.3), but an unquoted value matches too:
	 * clients still hold entity tags cached before the server started quoting them.
	 * The header is a list (section 3.2), so it is split by the commas outside of the quotes only:
	 * a comma inside a quoted entity tag belongs to the tag itself.
	 * An asterisk matches any entity tag as the whole header value only, not as a list item.
	 *
	 * @param string|null $ifNoneMatch
	 * @param string $entityTag
	 * @return bool
	 */
	public static function matches(?string $ifNoneMatch, string $entityTag): bool
	{
		if ($ifNoneMatch === null)
		{
			return false;
		}

		$entityTag = trim($entityTag, '"');
		if ($entityTag === '')
		{
			return false;
		}

		if (trim($ifNoneMatch) === '*')
		{
			return true;
		}

		foreach (self::splitList($ifNoneMatch) as $candidate)
		{
			$candidate = trim($candidate);
			if (str_starts_with($candidate, 'W/'))
			{
				$candidate = substr($candidate, 2);
			}

			if (trim($candidate, '"') === $entityTag)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Splits the If-None-Match list by the commas outside of the quotes:
	 * a comma is a valid entity-tag character by RFC 7232, section 2.3.
	 * An unbalanced quote leaves the rest of the header in a single item.
	 *
	 * @param string $ifNoneMatch
	 * @return string[]
	 */
	private static function splitList(string $ifNoneMatch): array
	{
		$items = [];
		$item = '';
		$quoted = false;

		for ($i = 0, $length = strlen($ifNoneMatch); $i < $length; $i++)
		{
			$char = $ifNoneMatch[$i];
			if ($char === '"')
			{
				$quoted = !$quoted;
			}
			elseif ($char === ',' && !$quoted)
			{
				$items[] = $item;
				$item = '';

				continue;
			}

			$item .= $char;
		}

		$items[] = $item;

		return $items;
	}
}
