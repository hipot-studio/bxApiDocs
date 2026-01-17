<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Currency;

use Bitrix\Currency\UserField\Types\MoneyType;
use Bitrix\Intranet\Internal\Entity\User\Field\ConvertableToUserFieldValue;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Contract\Arrayable;
use Stringable;

class Money implements Arrayable, ConvertableToUserFieldValue, Stringable
{
	public function __construct(
		public readonly float $amount,
		public readonly string $currencyCode,
	)
	{
	}

	public static function createFromUserFieldValue(string $value): ?static
	{
		if (!Loader::includeModule('currency'))
		{
			return null;
		}

		$explode = MoneyType::unFormatFromDB($value);

		return new static((float)$explode[0], $explode[1]);
	}

	public function toArray(): array
	{
		return [
			'amount' => $this->amount,
			'currency' => $this->currencyCode,
		];
	}

	public function toUserFieldValue(): string
	{
		if (!Loader::includeModule('currency'))
		{
			return (string)$this->amount;
		}

		return MoneyType::formatToDb((string)$this->amount, $this->currencyCode);
	}

	public function __toString(): string
	{
		if (!Loader::includeModule('currency'))
		{
			return (string)$this->amount;
		}

		$result = \CCurrencyLang::CurrencyFormat($this->amount, $this->currencyCode);

		return is_string($result) ? $result : '';
	}
}
