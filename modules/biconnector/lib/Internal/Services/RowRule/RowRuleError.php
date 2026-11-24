<?php

namespace Bitrix\BIConnector\Internal\Services\RowRule;

use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;

/**
 * Errors of the row rule API, each one a phrase the interface shows as is.
 */
final class RowRuleError
{
	public const FEATURE_DISABLED = 'ROW_RULE_FEATURE_DISABLED';
	public const ACCESS_DENIED = 'ROW_RULE_ACCESS_DENIED';
	public const NOT_FOUND = 'ROW_RULE_NOT_FOUND';
	public const REQUIRED = 'ROW_RULE_REQUIRED';
	public const TABLE_NOT_FOUND = 'ROW_RULE_TABLE_NOT_FOUND';
	public const FIELD_NOT_FOUND = 'ROW_RULE_FIELD_NOT_FOUND';
	public const OPERATOR_INVALID = 'ROW_RULE_OPERATOR_INVALID';
	public const VALUE_INVALID = 'ROW_RULE_VALUE_INVALID';
	public const TOKEN_NOT_ALLOWED = 'ROW_RULE_TOKEN_NOT_ALLOWED';
	public const TOO_MANY_VALUES = 'ROW_RULE_TOO_MANY_VALUES';
	public const TOO_LARGE = 'ROW_RULE_TOO_LARGE';
	public const DESCRIPTION_TOO_LONG = 'ROW_RULE_DESCRIPTION_TOO_LONG';
	public const DUPLICATE = 'ROW_RULE_DUPLICATE';

	/** Code to phrase; validation reports the first error in this order. */
	private const PHRASES = [
		self::FEATURE_DISABLED => 'BICONNECTOR_ROW_RULE_ERROR_FEATURE_DISABLED',
		self::ACCESS_DENIED => 'BICONNECTOR_ROW_RULE_ERROR_ACCESS_DENIED',
		self::NOT_FOUND => 'BICONNECTOR_ROW_RULE_ERROR_NOT_FOUND',
		self::REQUIRED => 'BICONNECTOR_ROW_RULE_ERROR_REQUIRED',
		self::TABLE_NOT_FOUND => 'BICONNECTOR_ROW_RULE_ERROR_TABLE_NOT_FOUND',
		self::FIELD_NOT_FOUND => 'BICONNECTOR_ROW_RULE_ERROR_FIELD_NOT_FOUND',
		self::OPERATOR_INVALID => 'BICONNECTOR_ROW_RULE_ERROR_OPERATOR_INVALID',
		self::VALUE_INVALID => 'BICONNECTOR_ROW_RULE_ERROR_VALUE_INVALID',
		self::TOKEN_NOT_ALLOWED => 'BICONNECTOR_ROW_RULE_ERROR_TOKEN_NOT_ALLOWED',
		self::TOO_MANY_VALUES => 'BICONNECTOR_ROW_RULE_ERROR_TOO_MANY_VALUES',
		self::TOO_LARGE => 'BICONNECTOR_ROW_RULE_ERROR_TOO_LARGE',
		self::DESCRIPTION_TOO_LONG => 'BICONNECTOR_ROW_RULE_ERROR_DESCRIPTION_TOO_LONG',
		self::DUPLICATE => 'BICONNECTOR_ROW_RULE_ERROR_DUPLICATE',
	];

	/** Codes whose phrase names the limit taken from `customData['limit']`. */
	private const LIMIT_CODES = [
		self::TOO_MANY_VALUES,
		self::TOO_LARGE,
		self::DESCRIPTION_TOO_LONG,
	];

	/**
	 * @return string[] Every code of the contract, in the order validation checks them.
	 */
	public static function getCodes(): array
	{
		return array_keys(self::PHRASES);
	}

	/**
	 * @param string $code One of the class constants.
	 * @param array $customData Passed to the client as is; null when empty.
	 *
	 * @return UserFacingError
	 *
	 * @throws ArgumentException When the code is not one of the contract.
	 */
	public static function create(string $code, array $customData = []): UserFacingError
	{
		$phraseId = self::PHRASES[$code] ?? null;
		if ($phraseId === null)
		{
			throw new ArgumentException("Unknown row rule error code: {$code}", 'code');
		}

		if (in_array($code, self::LIMIT_CODES, true))
		{
			$limit = (int)($customData['limit'] ?? 0);
			$message = Loc::getMessagePlural($phraseId, $limit, ['#LIMIT#' => self::formatNumber($limit)]);
		}
		else
		{
			$message = Loc::getMessage($phraseId);
		}

		return new UserFacingError((string)$message, $code, $customData === [] ? null : $customData);
	}

	/**
	 * Groups thousands by the culture of the portal, as other limit messages of the module do.
	 */
	private static function formatNumber(int $value): string
	{
		$culture = Context::getCurrent()?->getCulture();
		if ($culture === null || $value < 10000)
		{
			return (string)$value;
		}

		return number_format(
			(float)$value,
			0,
			$culture->getNumberDecimalSeparator(),
			$culture->getNumberThousandsSeparator(),
		);
	}
}
