<?php

namespace Bitrix\Sign\Helper\Field;

use Bitrix\Main;
use Bitrix\Sign\Type\BlockCode;

final class NameHelper
{
	public static function create(
		string $blockCode,
		string $fieldType,
		int $party,
		?string $fieldCode = null,
		?string $subfieldCode = null
	): string
	{
		if (BlockCode::isCommon($blockCode))
		{
			$fieldCode ??= Main\Security\Random::getString(32) . time();
		}
		// signature and stamp block has no fieldCode
		$fieldCode ??= '__' . $blockCode;

		return "$fieldCode.$fieldType.$blockCode.$party.$subfieldCode";
	}

	/**
	 * @return array{fieldCode: string, fieldType: string, blockCode: string, party: int, subfieldCode: string}
	 * @see static::createFieldName
	 */
	public static function parse(string $fieldName): array
	{
		$data = explode(".", $fieldName);
		return [
			'fieldCode' => $data[0] ?? '',
			'fieldType' => $data[1] ?? '',
			'blockCode' => $data[2] ?? '',
			'party' => (int)($data[3] ?? -1),
			'subfieldCode' => $data[4] ?? ''
		];
	}

	public static function parseFieldCode(string $fieldCode, string $entityType): array
	{
		if (str_starts_with($fieldCode, $entityType))
		{
			$fieldName = mb_substr($fieldCode, (mb_strlen($entityType) + 1));
			$fieldEntityType = $entityType;
		}
		else
		{
			[$fieldEntityType, $fieldName] = explode('_', $fieldCode, 2);
		}

		return [$fieldEntityType, $fieldName];
	}

	public static function isValidParsedField(array $parsedArray): bool
	{
		if (
			!isset(
				$parsedArray['fieldCode'],
				$parsedArray['fieldType'],
				$parsedArray['blockCode'],
				$parsedArray['party'],
				$parsedArray['subfieldCode']
			)
		)
		{
			return false;
		}

		return is_string($parsedArray['fieldCode']) && !empty($parsedArray['fieldCode'])
			&& is_string($parsedArray['fieldType']) && !empty($parsedArray['fieldType'])
			&& is_string($parsedArray['blockCode']) && !empty($parsedArray['blockCode'])
			&& is_int($parsedArray['party']) && $parsedArray['party'] >= 0
			&& is_string($parsedArray['subfieldCode']);
	}

	public static function isValidFieldName(string $fieldName): bool
	{
		if (empty($fieldName))
		{
			return false;
		}

		$parsed = self::parse($fieldName);

		return self::isValidParsedField($parsed);
	}
}
