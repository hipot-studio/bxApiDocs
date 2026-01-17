<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

use Bitrix\Intranet\Internal\Integration\Currency\CurrencyProvider;
use Bitrix\Intranet\Internal\Integration\Currency\Entity\CurrencyCollection;
use Bitrix\Intranet\Internal\Integration\Currency\Money;

class MoneyField extends SingleField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		public readonly bool $isVisible,
		public readonly CurrencyCollection $currencies,
		public readonly mixed $value = null,
	)
	{
	}

	public static function createByData(array $fieldData, mixed $value): static
	{
		return new static(
			id: $fieldData['name'],
			title: $fieldData['title'],
			isEditable: $fieldData['editable'] ?? false,
			isShowAlways: $fieldData['showAlways'] ?? false,
			isVisible: $fieldData['isVisible'] ?? false,
			currencies: (new CurrencyProvider())->getAvailableCurrencyCollection(),
			value: static::parseValue($value),
		);
	}

	protected static function parseSingleValue(mixed $value): mixed
	{
		if (is_string($value))
		{
			return Money::createFromUserFieldValue($value) ?? $value;
		}

		return $value;
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			'currencies' => $this->currencies->toArray(),
		];
	}

	public function isValid(mixed $value = null): bool
	{
		return $value instanceof Money
			&& $this->currencies->findById($value->currencyCode);
	}
}
