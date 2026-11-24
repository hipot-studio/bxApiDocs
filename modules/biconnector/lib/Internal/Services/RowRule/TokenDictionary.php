<?php

namespace Bitrix\BIConnector\Internal\Services\RowRule;

use Bitrix\BIConnector\DataSource\FieldKind;
use Bitrix\Main\Localization\Loc;

/**
 * Tokens of row rules: values resolved for the user who views the data.
 *
 * The API speaks in codes (`ME`), the stored value list keeps the short keys (`me`) of the storage format.
 */
final class TokenDictionary
{
	public const ME = 'ME';
	public const MY_DEPT = 'MY_DEPT';
	public const MY_DEPT_TREE = 'MY_DEPT_TREE';
	public const MY_LED_DEPTS_TREE = 'MY_LED_DEPTS_TREE';

	/** Code to stored key, in the order tokens are listed and sorted. */
	private const STORED_KEYS = [
		self::ME => 'me',
		self::MY_DEPT => 'dept',
		self::MY_DEPT_TREE => 'dept_tree',
		self::MY_LED_DEPTS_TREE => 'led_dept_tree',
	];

	private const ALLOWED_BY_KIND = [
		FieldKind::EMPLOYEE => [
			self::ME,
		],
		FieldKind::DEPARTMENT => [
			self::MY_DEPT,
			self::MY_DEPT_TREE,
			self::MY_LED_DEPTS_TREE,
		],
	];

	/**
	 * @param string $kind Field kind, '' for an ordinary field.
	 *
	 * @return string[] Token codes in a fixed order.
	 */
	public static function getAllowed(string $kind): array
	{
		return self::ALLOWED_BY_KIND[$kind] ?? [];
	}

	public static function toStored(string $code): ?string
	{
		return self::STORED_KEYS[$code] ?? null;
	}

	public static function fromStored(string $storedKey): ?string
	{
		$code = array_search($storedKey, self::STORED_KEYS, true);

		return $code === false ? null : $code;
	}

	public static function getTitle(string $code): string
	{
		if (!isset(self::STORED_KEYS[$code]))
		{
			return '';
		}

		return (string)Loc::getMessage('BICONNECTOR_ROW_RULE_TOKEN_' . $code);
	}

	/**
	 * Position of a stored key among tokens; anything else goes after every token.
	 *
	 * @param string $storedKey
	 *
	 * @return int
	 */
	public static function getOrder(string $storedKey): int
	{
		$position = array_search($storedKey, array_values(self::STORED_KEYS), true);

		return $position === false ? PHP_INT_MAX : $position;
	}
}
