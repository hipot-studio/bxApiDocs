<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Currency;

use Bitrix\Currency\CurrencyTable;
use Bitrix\Main\Loader;

final class CurrencyGateway
{
	private const SELECT = [
		'CURRENCY',
		'FULL_NAME' => 'CURRENT_LANG_FORMAT.FULL_NAME',
		'SORT',
		'AMOUNT',
		'AMOUNT_CNT',
		'BASE',
		'CURRENT_BASE_RATE',
	];

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
	public function getList(array $order, ?int $limit = null, int $offset = 0): array
	{
		Loader::requireModule('currency');

		$query = CurrencyTable::query()
			->setSelect(self::SELECT)
			->setOrder($order)
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset > 0)
		{
			$query->setOffset($offset);
		}

		$currencies = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$currencies[] = $this->mapRow($row);
		}

		return $currencies;
	}

	public function getCount(): int
	{
		Loader::requireModule('currency');

		return CurrencyTable::getCount();
	}

	/** @return list<string> */
	public function getCodes(): array
	{
		Loader::requireModule('currency');

		$codes = [];
		$result = CurrencyTable::query()
			->setSelect(['CURRENCY'])
			->setOrder(['CURRENCY' => 'ASC'])
			->exec()
		;
		while ($row = $result->fetch())
		{
			$codes[] = (string)$row['CURRENCY'];
		}

		return $codes;
	}

	/** @return array{CURRENCY: string, BASE: bool}|null */
	public function getDeletionState(string $currencyCode): ?array
	{
		return $this->getDeletionStates([$currencyCode])[$currencyCode] ?? null;
	}

	/**
	 * @param list<string> $currencyCodes
	 * @return array<string, array{CURRENCY: string, BASE: bool}>
	 */
	public function getDeletionStates(array $currencyCodes): array
	{
		if ($currencyCodes === [])
		{
			return [];
		}

		Loader::requireModule('currency');

		$states = [];
		$result = CurrencyTable::query()
			->setSelect(['CURRENCY', 'BASE'])
			->whereIn('CURRENCY', $currencyCodes)
			->exec()
		;
		while ($row = $result->fetch())
		{
			$currencyCode = (string)$row['CURRENCY'];
			$states[$currencyCode] = [
				'CURRENCY' => $currencyCode,
				'BASE' => $row['BASE'] === true || $row['BASE'] === 'Y',
			];
		}

		return $states;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{
	 *     CURRENCY: string,
	 *     FULL_NAME: string,
	 *     SORT: int,
	 *     AMOUNT: float,
	 *     AMOUNT_CNT: int,
	 *     BASE: bool,
	 *     CURRENT_BASE_RATE: float|null
	 * }
	 */
	private function mapRow(array $row): array
	{
		$currentBaseRate = $row['CURRENT_BASE_RATE'] ?? null;

		return [
			'CURRENCY' => (string)$row['CURRENCY'],
			'FULL_NAME' => (string)($row['FULL_NAME'] ?? ''),
			'SORT' => (int)$row['SORT'],
			'AMOUNT' => (float)$row['AMOUNT'],
			'AMOUNT_CNT' => (int)$row['AMOUNT_CNT'],
			'BASE' => $row['BASE'] === true || $row['BASE'] === 'Y',
			'CURRENT_BASE_RATE' => $currentBaseRate === null ? null : (float)$currentBaseRate,
		];
	}
}
