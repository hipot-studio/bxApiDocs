<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

use Bitrix\Crm\Format\Money;
use Bitrix\Main\Type\Contract\Arrayable;
use CCrmCurrency;

final readonly class Amount implements \JsonSerializable, Arrayable
{
	public function __construct(
		private float $amount,
	)
	{
	}

	public function toArray(): array
	{
		$currencyId = CCrmCurrency::GetAccountCurrencyID();

		return [
			'amount_number' => $this->amount,
			'currency_id' => $currencyId,
			'amount_with_currency' => html_entity_decode(
				Money::format($this->amount, $currencyId),
				ENT_QUOTES | ENT_HTML5,
				'UTF-8',
			),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
