<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Currency;

use Bitrix\Crm\V2\Internal\Service\Currency\Compatibility\LegacyCurrencyMutationAdapter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

final class CurrencyMutationService
{
	public const ERROR_CURRENCY_NOT_FOUND = 'CRM_CURRENCY_NOT_FOUND';
	public const ERROR_ACCOUNTING_CURRENCY_DELETE = 'CRM_ACCOUNTING_CURRENCY_DELETE';
	public const ERROR_BUSY = 'CRM_CURRENCY_MUTATION_BUSY';
	public const MUTATION_LOCK_NAME = 'crm_currency_mutation';

	private const MUTATION_LOCK_TIMEOUT = 5;

	public function __construct(
		private readonly LegacyCurrencyMutationAdapter $adapter = new LegacyCurrencyMutationAdapter(),
	)
	{
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public function update(string $currencyId, array $fields): Result
	{
		$updateFields = $this->normalizeUpdateFields($fields);
		if ($updateFields !== [])
		{
			$result = $this->adapter->update($currencyId, $updateFields);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		if (!isset($fields['ACCOUNTING']))
		{
			return new Result();
		}

		return $this->updateAccountingCurrency($currencyId, (string)$fields['ACCOUNTING']);
	}

	public function setBaseCurrency(string $currencyId): Result
	{
		return $this->executeLocked(
			fn(): Result => $this->adapter->setBaseCurrency($currencyId),
			'CRM_CURRENCY_MUTATION_SET_BASE_FAILED',
		);
	}

	public function setAccountingCurrency(string $currencyId): Result
	{
		return $this->executeLocked(
			fn(): Result => $this->setAccountingCurrencyUnlocked($currencyId),
			'CRM_CURRENCY_MUTATION_SET_ACCOUNTING_FAILED',
		);
	}

	public function delete(string $currencyId): Result
	{
		return $this->deleteBatch([$currencyId])[$currencyId];
	}

	/**
	 * @param list<string> $currencyIds
	 * @return array<string, Result>
	 */
	public function deleteBatch(array $currencyIds): array
	{
		if ($currencyIds === [])
		{
			return [];
		}

		if (!$this->adapter->acquireMutationLock(self::MUTATION_LOCK_NAME, self::MUTATION_LOCK_TIMEOUT))
		{
			$results = [];
			foreach ($currencyIds as $currencyId)
			{
				$results[$currencyId] = $this->createErrorResult(
					'CRM_CURRENCY_MUTATION_DELETE_FAILED',
					self::ERROR_BUSY,
				);
			}

			return $results;
		}

		try
		{
			$accountingCurrencyId = $this->adapter->getAccountingCurrencyId();
			$results = [];
			foreach ($currencyIds as $currencyId)
			{
				$results[$currencyId] = $currencyId === $accountingCurrencyId
					? $this->createErrorResult(
						'CRM_CURRENCY_MUTATION_DELETE_FAILED',
						self::ERROR_ACCOUNTING_CURRENCY_DELETE,
					)
					: $this->adapter->delete($currencyId)
				;
			}

			return $results;
		}
		finally
		{
			$this->adapter->releaseMutationLock(self::MUTATION_LOCK_NAME);
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, int|float>
	 */
	private function normalizeUpdateFields(array $fields): array
	{
		$normalized = [];
		if (isset($fields['SORT']))
		{
			$normalized['SORT'] = (int)$fields['SORT'];
		}

		if (isset($fields['EXCH_RATE']))
		{
			$normalized['AMOUNT'] = (float)$fields['EXCH_RATE'];
		}

		if (isset($fields['AMOUNT_CNT']))
		{
			$normalized['AMOUNT_CNT'] = max(1, (int)$fields['AMOUNT_CNT']);
		}

		return $normalized;
	}

	private function updateAccountingCurrency(string $currencyId, string $accounting): Result
	{
		if ($accounting !== 'Y' && $accounting !== 'N')
		{
			return new Result();
		}

		return $this->executeLocked(
			function() use ($currencyId, $accounting): Result {
				$accountingCurrencyId = $this->adapter->getAccountingCurrencyId();
				if ($accounting === 'Y' && $currencyId !== $accountingCurrencyId)
				{
					return $this->setAccountingCurrencyUnlocked($currencyId);
				}

				$baseCurrencyId = $this->adapter->getBaseCurrencyId();
				if (
					$accounting === 'N'
					&& $currencyId === $accountingCurrencyId
					&& $currencyId !== $baseCurrencyId
				)
				{
					return $this->setAccountingCurrencyUnlocked($baseCurrencyId);
				}

				return new Result();
			},
			'CRM_CURRENCY_MUTATION_SET_ACCOUNTING_FAILED',
		);
	}

	private function setAccountingCurrencyUnlocked(string $currencyId): Result
	{
		if (!$this->adapter->currencyExists($currencyId))
		{
			return $this->createErrorResult(
				'CRM_CURRENCY_MUTATION_NOT_FOUND',
				self::ERROR_CURRENCY_NOT_FOUND,
			);
		}

		return $this->adapter->setAccountingCurrency($currencyId);
	}

	/** @param callable(): Result $mutation */
	private function executeLocked(callable $mutation, string $failureMessageCode): Result
	{
		if (!$this->adapter->acquireMutationLock(self::MUTATION_LOCK_NAME, self::MUTATION_LOCK_TIMEOUT))
		{
			return $this->createErrorResult($failureMessageCode, self::ERROR_BUSY);
		}

		try
		{
			return $mutation();
		}
		finally
		{
			$this->adapter->releaseMutationLock(self::MUTATION_LOCK_NAME);
		}
	}

	private function createErrorResult(string $messageCode, string $errorCode): Result
	{
		return (new Result())->addError(new Error((string)Loc::getMessage($messageCode), $errorCode));
	}
}
