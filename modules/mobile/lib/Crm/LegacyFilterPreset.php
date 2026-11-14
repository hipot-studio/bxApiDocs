<?php

namespace Bitrix\Mobile\Crm;

/**
 * The user preset of legacy mobile CRM lists lives in user options, which any user can overwrite
 * with arbitrary data, so it must not reach GetList as is: service keys like __CONDITIONS or
 * CHECK_PERMISSIONS turn into raw SQL or disable access checks.
 */
final class LegacyFilterPreset
{
	public static function sanitize(mixed $fields, array $fieldDefinitions): array
	{
		if (!is_array($fields))
		{
			return [];
		}

		$allowedKeys = self::getAllowedKeys($fieldDefinitions);
		$result = [];
		foreach ($fields as $key => $value)
		{
			if (isset($allowedKeys[$key]) && is_scalar($value))
			{
				$result[$key] = (string)$value;
			}
		}

		return $result;
	}

	private static function getAllowedKeys(array $fieldDefinitions): array
	{
		$keys = [];
		foreach ($fieldDefinitions as $definition)
		{
			$id = (string)($definition['id'] ?? '');
			if ($id === '')
			{
				continue;
			}

			if (($definition['type'] ?? '') === 'number')
			{
				$keys[$id . '_from'] = true;
				$keys[$id . '_to'] = true;
			}
			else
			{
				$keys[$id] = true;
			}
		}

		return $keys;
	}
}
