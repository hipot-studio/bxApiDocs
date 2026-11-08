<?php

namespace Bitrix\Catalog\Integration\Report\Quantity;

final class ProductRowQuantityCalculator
{
	/**
	 * @param array $source AMOUNT, QUANTITY_RESERVED, movements within the period (RECEIVED, OUTGOING, SOLD)
	 * and after its end (RECEIVED_AFTER, OUTGOING_AFTER, SOLD_AFTER).
	 */
	public function calculate(array $source): array
	{
		$amount = QuantityNormalizer::normalize(
			$this->getValue($source, 'AMOUNT')
			- $this->getValue($source, 'RECEIVED_AFTER')
			+ $this->getValue($source, 'OUTGOING_AFTER')
			+ $this->getValue($source, 'SOLD_AFTER')
		);
		$received = QuantityNormalizer::normalize($this->getValue($source, 'RECEIVED'));
		$sold = QuantityNormalizer::normalize($this->getValue($source, 'SOLD'));
		$reserved = QuantityNormalizer::normalize($this->getValue($source, 'QUANTITY_RESERVED'));

		return [
			'AMOUNT' => $amount,
			'STARTING_QUANTITY' => QuantityNormalizer::normalize(
				$amount - $received + $this->getValue($source, 'OUTGOING') + $sold
			),
			'RECEIVED_QUANTITY' => $received,
			'AMOUNT_SOLD' => $sold,
			'QUANTITY_RESERVED' => $reserved,
			'QUANTITY' => QuantityNormalizer::normalize($amount - $reserved),
		];
	}

	private function getValue(array $source, string $key): float
	{
		return (float)($source[$key] ?? 0.0);
	}
}
