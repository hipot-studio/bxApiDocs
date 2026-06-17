<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField\Converter;

use Bitrix\Bizproc\FieldType;

class UserConverter implements TypeConverterInterface
{
	private array $documentType;

	public function __construct()
	{
		$this->documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
	}

	public static function getType(): string
	{
		return FieldType::USER;
	}

	/**
	 * @return string|string[]|null
	 */
	public function toStorage(mixed $value): mixed
	{
		if (!isset($value) || $value === '')
		{
			return null;
		}

		$trimmed = trim((string)$value);
		$listValue = $this->extractListValues($trimmed);
		if ($listValue !== null)
		{
			return $listValue;
		}

		return $this->convertSingleValue($trimmed);
	}

	private function extractListValues(string $value): ?array
	{
		$separatorPattern = null;

		if (
			str_contains($value, ';')
			|| (
				str_contains($value, ',')
				&& (
					preg_match_all('#\[[^][]+\]#u', $value) > 1
					|| preg_match_all('#(?:user|group)_[^,\s]+#iu', $value) > 1
				)
			)
		)
		{
			$separatorPattern = '#\s*[;,]\s*#u';
		}

		if ($separatorPattern === null)
		{
			return null;
		}

		$parts = preg_split($separatorPattern, $value, -1, PREG_SPLIT_NO_EMPTY);
		if (!$parts || count($parts) <= 1)
		{
			return null;
		}

		$result = [];
		foreach ($parts as $part)
		{
			$converted = $this->convertSingleValue(trim($part));
			foreach ((array)$converted as $userId)
			{
				$userId = (string)$userId;
				if ($userId !== '')
				{
					$result[$userId] = $userId;
				}
			}
		}

		return $result ? array_values($result) : null;
	}

	private function convertSingleValue(string $value): mixed
	{
		if (preg_match('#^[0-9]+$#', $value))
		{
			return $value;
		}

		if (preg_match('#\[(\d+)\]#', $value, $matches))
		{
			$value = 'user_' . $matches[1];
		}

		if (preg_match('#\[(.+?)\]#', $value, $matches))
		{
			$value = 'group_' . strtolower($matches[1]);
		}

		if (mb_strpos($value, 'user_') !== false)
		{
			return \CBPHelper::extractFirstUser($value, $this->documentType);
		}

		if (mb_strpos($value, 'group_') !== false)
		{
			return \CBPHelper::extractUsers($value, $this->documentType);
		}

		return null;
	}

	public function fromStorage(mixed $value): mixed
	{
		if (!isset($value) || $value === '')
		{
			return null;
		}

		return 'user_' . $value;
	}
}
