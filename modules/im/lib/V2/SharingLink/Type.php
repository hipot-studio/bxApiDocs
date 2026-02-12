<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

enum Type: string implements \JsonSerializable
{
	case Primary = 'PRIMARY';
	case Individual = 'INDIVIDUAL';
	case Custom = 'CUSTOM';

	public function isUnique(): bool
	{
		return match ($this) {
			self::Primary, self::Individual => true,
			self::Custom => false,
		};
	}

	/**
	 * @return array<string>
	 */
	public static function getValues(): array
	{
		$values = [];
		foreach (self::cases() as $case)
		{
			$values[] = $case->value;
		}

		return $values;
	}

	public function jsonSerialize(): string
	{
		return $this->value;
	}
}
