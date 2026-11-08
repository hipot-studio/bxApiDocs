<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Currency\Compatibility;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

/**
 * Temporary boundary for currency mutations that have not yet moved to CRM V2.
 *
 * Remove it after the affected operations move to V2 and CCrmCurrency delegates to the new service.
 */
class LegacyCurrencyMutationAdapter
{
	public const ERROR_UPDATE_FAILED = 'CRM_CURRENCY_UPDATE_FAILED';
	public const ERROR_SET_BASE_FAILED = 'CRM_CURRENCY_SET_BASE_FAILED';
	public const ERROR_SET_ACCOUNTING_FAILED = 'CRM_CURRENCY_SET_ACCOUNTING_FAILED';
	public const ERROR_DELETE_FAILED = 'CRM_CURRENCY_DELETE_FAILED';

	/**
	 * @param array<string, mixed> $fields
	 */
	public function update(string $currencyId, array $fields): Result
	{
		$previousError = $this->getLegacyLastError();
		if ($this->updateLegacy($currencyId, $fields))
		{
			return new Result();
		}

		return $this->createFailureResult(
			$previousError,
			'CRM_CURRENCY_MUTATION_UPDATE_FAILED',
			self::ERROR_UPDATE_FAILED,
		);
	}

	public function setBaseCurrency(string $currencyId): Result
	{
		$previousError = $this->getLegacyLastError();
		if ($this->setBaseCurrencyLegacy($currencyId))
		{
			return new Result();
		}

		return $this->createFailureResult(
			$previousError,
			'CRM_CURRENCY_MUTATION_SET_BASE_FAILED',
			self::ERROR_SET_BASE_FAILED,
		);
	}

	public function setAccountingCurrency(string $currencyId): Result
	{
		$previousError = $this->getLegacyLastError();
		$this->setAccountingCurrencyLegacy($currencyId);
		if ($this->getStoredAccountingCurrencyIdLegacy() !== $currencyId)
		{
			return $this->createFailureResult(
				$previousError,
				'CRM_CURRENCY_MUTATION_SET_ACCOUNTING_FAILED',
				self::ERROR_SET_ACCOUNTING_FAILED,
			);
		}

		return new Result();
	}

	public function currencyExists(string $currencyId): bool
	{
		return $this->currencyExistsLegacy($currencyId);
	}

	public function delete(string $currencyId): Result
	{
		$previousError = $this->getLegacyLastError();
		if ($this->deleteLegacy($currencyId))
		{
			return new Result();
		}

		return $this->createFailureResult(
			$previousError,
			'CRM_CURRENCY_MUTATION_DELETE_FAILED',
			self::ERROR_DELETE_FAILED,
		);
	}

	public function getBaseCurrencyId(): string
	{
		return $this->getBaseCurrencyIdLegacy();
	}

	public function getAccountingCurrencyId(): string
	{
		$accountingCurrencyId = $this->getStoredAccountingCurrencyIdLegacy();

		return $accountingCurrencyId !== ''
			? $accountingCurrencyId
			: $this->getBaseCurrencyIdLegacy();
	}

	public function acquireMutationLock(string $lockName, int $timeout): bool
	{
		return Application::getConnection()->lock($lockName, $timeout);
	}

	public function releaseMutationLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	private function createFailureResult(string $previousError, string $fallbackMessageCode, string $errorCode): Result
	{
		$currentError = $this->getLegacyLastError();
		$message = $currentError !== '' && $currentError !== $previousError
			? $currentError
			: (string)Loc::getMessage($fallbackMessageCode);

		return (new Result())->addError(new Error($message, $errorCode));
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	protected function updateLegacy(string $currencyId, array $fields): bool
	{
		return \CCrmCurrency::Update($currencyId, $fields);
	}

	protected function setBaseCurrencyLegacy(string $currencyId): bool
	{
		return \CCrmCurrency::SetBaseCurrencyID($currencyId);
	}

	protected function setAccountingCurrencyLegacy(string $currencyId): void
	{
		\CCrmCurrency::SetAccountCurrencyID($currencyId);
	}

	protected function currencyExistsLegacy(string $currencyId): bool
	{
		return \CCurrency::GetByID($currencyId) !== false;
	}

	protected function deleteLegacy(string $currencyId): bool
	{
		return \CCrmCurrency::Delete($currencyId);
	}

	protected function getBaseCurrencyIdLegacy(): string
	{
		return (string)\CCrmCurrency::GetBaseCurrencyID();
	}

	protected function getAccountingCurrencyIdLegacy(): string
	{
		return (string)\CCrmCurrency::GetAccountCurrencyID();
	}

	protected function getStoredAccountingCurrencyIdLegacy(): string
	{
		return Option::get('crm', 'account_currency_id', '', '');
	}

	protected function getLegacyLastError(): string
	{
		return (string)\CCrmCurrency::GetLastError();
	}
}
