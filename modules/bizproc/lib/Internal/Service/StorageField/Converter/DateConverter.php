<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField\Converter;

use Bitrix\Bizproc\BaseType\Value;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Type\Date;

class DateConverter implements TypeConverterInterface
{
	protected const FORMAT = 'Y-m-d';

	public static function getType(): string
	{
		return FieldType::DATE;
	}

	public function toStorage(mixed $value): mixed
	{
		if (!isset($value) || $value === '')
		{
			return $value;
		}

		$timestamp = $this->resolveTimestamp($value);

		if ($timestamp !== null)
		{
			return Date::createFromTimestamp($timestamp)->format(static::FORMAT);
		}

		return null;
	}

	public function fromStorage(mixed $value): mixed
	{
		if (!isset($value) || $value === '')
		{
			return $value;
		}

		if ($value instanceof Value\Date)
		{
			return $value;
		}

		if ($value instanceof Value\DateTime)
		{
			return $value;
		}

		if (!is_string($value))
		{
			return $value;
		}

		$ts = strtotime($value);
		if ($ts === false)
		{
			return $value;
		}

		$userOffset = \CTimeZone::GetOffset();
		$ts += $userOffset;

		return $this->createValueObject($ts);
	}

	protected function createValueObject(int $timestamp): Value\Date
	{
		return new Value\Date($timestamp);
	}

	protected function resolveTimestamp(mixed $value): ?int
	{
		if ($value instanceof Value\Date)
		{
			return $value->getTimestamp();
		}

		if (is_string($value) && preg_match('#(.+)\s\[([0-9\-]+)\]#', $value, $m))
		{
			$userOffset = (int)$m[2];

			try
			{
				$userDateTime = new \Bitrix\Main\Type\DateTime(
					trim($m[1]),
					null,
					new \DateTimeZone(\Bitrix\Main\Type\DateTime::secondsToOffset($userOffset))
				);

				return $userDateTime->getTimestamp();
			}
			catch (\Bitrix\Main\ObjectException)
			{
				return null;
			}
		}

		if (is_string($value))
		{
			$ts = strtotime($value);

			return $ts !== false ? $ts : null;
		}

		return null;
	}
}
