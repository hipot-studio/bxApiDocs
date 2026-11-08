<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Param;

use Bitrix\Main\Config\Option;

class Duration extends BaseParam
{
	private const MAX_SECONDS = 300;

	public function getValue(): int
	{
		return (int)$this->value;
	}

	protected static function getParamName(): ParamName
	{
		return ParamName::Duration;
	}

	public function toArray(): array
	{
		return [
			'DISK_FILE_ID' => $this->fileId,
			'PARAM_NAME' => ParamName::Duration->value,
			'PARAM_VALUE' => (string)$this->getValue(),
		];
	}

	public static function isValidValue(string $value): bool
	{
		if (preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return false;
		}

		$seconds = (int)$value;

		return $seconds > 0 && $seconds <= self::getMaxSeconds();
	}

	public static function getMaxSeconds(): int
	{
		$maxSeconds = (int)Option::get('im', 'voice_message_max_duration', (string)self::MAX_SECONDS);

		return $maxSeconds > 0 ? $maxSeconds : self::MAX_SECONDS;
	}
}
