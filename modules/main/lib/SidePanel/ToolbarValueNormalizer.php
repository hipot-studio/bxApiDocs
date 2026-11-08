<?php

namespace Bitrix\Main\SidePanel;

/**
 * Declares the stored form of the toolbar identifiers in one place. The save modifiers of the
 * fields and every lookup by them have to go through this class, otherwise a lookup misses the
 * row it has written.
 */
final class ToolbarValueNormalizer
{
	public static function normalize(string $value): string
	{
		return mb_strtolower($value);
	}
}
