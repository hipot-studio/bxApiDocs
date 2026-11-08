<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Hub;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * The rule of the description field of the automated solution and smart process hubs.
 *
 * The client applies the same rule (normalizeHubDescription() in crm.settings-hub), so the whitespace set is listed
 * explicitly and matches String.prototype.trim(): neither PHP trim() nor \s cover the same characters.
 *
 * @internal
 */
final class DescriptionNormalizer
{
	public const MAX_LENGTH = 1000;

	private const EDGE_WHITESPACE_PATTERN = '/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|([^\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}])[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u';

	public function normalize(mixed $value): Result
	{
		$result = new Result();

		if ($value === null)
		{
			return $result->setData(['description' => null]);
		}

		if (!is_string($value) || !mb_check_encoding($value, 'UTF-8'))
		{
			return $result->addError($this->createInvalidError());
		}

		$value = str_replace(["\r\n", "\r"], "\n", $value);
		$value = preg_replace(self::EDGE_WHITESPACE_PATTERN, '$1', $value);
		if ($value === null)
		{
			return $result->addError($this->createInvalidError());
		}

		if ($value === '')
		{
			return $result->setData(['description' => null]);
		}

		if (mb_strlen($value, 'UTF-8') > self::MAX_LENGTH)
		{
			return $result->addError(new Error(
				(string)Loc::getMessage('CRM_HUB_DESCRIPTION_TOO_LONG'),
				'DESCRIPTION_TOO_LONG',
			));
		}

		return $result->setData(['description' => $value]);
	}

	private function createInvalidError(): Error
	{
		return new Error((string)Loc::getMessage('CRM_HUB_DESCRIPTION_INVALID'), 'DESCRIPTION_INVALID');
	}
}
