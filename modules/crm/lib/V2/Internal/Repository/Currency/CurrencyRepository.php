<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Currency;

use Bitrix\Crm\V2\Internal\Integration\Currency\CurrencyGateway;

final class CurrencyRepository
{
	private const ALLOWED_ORDER_FIELDS = [
		'CURRENCY' => true,
		'SORT' => true,
		'CURRENT_LANG_FORMAT.FULL_NAME' => true,
	];

	private const ALLOWED_ORDER_DIRECTIONS = [
		'ASC' => true,
		'DESC' => true,
	];

	private const DEFAULT_ORDER = [
		'SORT' => 'ASC',
		'CURRENCY' => 'ASC',
	];

	public function __construct(
		private readonly CurrencyGateway $gateway = new CurrencyGateway(),
	)
	{
	}

	/**
	 * @param array<string, string> $order
	 * @return list<array{
	 *     CURRENCY: string,
	 *     FULL_NAME: string,
	 *     SORT: int,
	 *     AMOUNT: float,
	 *     AMOUNT_CNT: int,
	 *     BASE: bool,
	 *     CURRENT_BASE_RATE: float|null
	 * }>
	 */
	public function getList(array $order = [], ?int $limit = null, int $offset = 0): array
	{
		$limit = $limit === null ? null : max(1, $limit);

		return $this->gateway->getList(
			$this->normalizeOrder($order),
			$limit,
			max(0, $offset),
		);
	}

	public function getCount(): int
	{
		return $this->gateway->getCount();
	}

	/** @return list<string> */
	public function getCodes(): array
	{
		return $this->gateway->getCodes();
	}

	/** @return array{CURRENCY: string, BASE: bool}|null */
	public function getDeletionState(string $currencyCode): ?array
	{
		return $this->gateway->getDeletionState($currencyCode);
	}

	/**
	 * @param list<string> $currencyCodes
	 * @return array<string, array{CURRENCY: string, BASE: bool}>
	 */
	public function getDeletionStates(array $currencyCodes): array
	{
		return $this->gateway->getDeletionStates($currencyCodes);
	}

	/**
	 * @param array<string, string> $order
	 * @return array<string, string>
	 */
	private function normalizeOrder(array $order): array
	{
		$normalizedOrder = [];
		foreach ($order as $field => $direction)
		{
			$direction = strtoupper((string)$direction);
			if (!isset(self::ALLOWED_ORDER_FIELDS[$field], self::ALLOWED_ORDER_DIRECTIONS[$direction]))
			{
				continue;
			}

			$normalizedOrder[$field] = $direction;
		}

		if ($normalizedOrder === [])
		{
			return self::DEFAULT_ORDER;
		}

		if (!isset($normalizedOrder['CURRENCY']))
		{
			$normalizedOrder['CURRENCY'] = 'ASC';
		}

		return $normalizedOrder;
	}
}
