<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Panel\Action;

use Bitrix\Crm\V2\Internal\Service\Currency\CurrencyMutationService;
use Bitrix\Main\Error;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\EditAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

final class CurrencyEditAction extends EditAction
{
	public const ERROR_TOO_MANY_ROWS = 'CRM_CURRENCY_GRID_EDIT_TOO_MANY_ROWS';
	public const ERROR_MULTIPLE_ACCOUNTING_CURRENCIES = 'CRM_CURRENCY_GRID_EDIT_MULTIPLE_ACCOUNTING_CURRENCIES';

	private const MAX_ROWS = 100;
	private const ALLOWED_FIELDS = [
		'SORT' => true,
		'EXCH_RATE' => true,
		'AMOUNT_CNT' => true,
		'ACCOUNTING' => true,
	];

	public function __construct(
		private readonly CurrencyMutationService $mutationService,
	)
	{
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		$rows = $request->getPost('FIELDS');
		if (!is_array($rows))
		{
			return null;
		}
		if (count($rows) > self::MAX_ROWS)
		{
			return (new Result())->addError(new Error(
				(string)Loc::getMessage('CRM_CURRENCY_GRID_EDIT_TOO_MANY_ROWS'),
				self::ERROR_TOO_MANY_ROWS,
			));
		}

		$accountingTransitions = 0;
		foreach ($rows as $currencyCode => $fields)
		{
			if (
				is_string($currencyCode)
				&& preg_match('/^[A-Z]{3}$/D', $currencyCode) === 1
				&& is_array($fields)
				&& ($fields['ACCOUNTING'] ?? null) === 'Y'
			)
			{
				$accountingTransitions++;
			}
		}

		if ($accountingTransitions > 1)
		{
			return (new Result())->addError(new Error(
				(string)Loc::getMessage('CRM_CURRENCY_GRID_EDIT_MULTIPLE_ACCOUNTING_CURRENCIES'),
				self::ERROR_MULTIPLE_ACCOUNTING_CURRENCIES,
			));
		}

		$result = new Result();
		foreach ($rows as $currencyCode => $fields)
		{
			if (
				!is_string($currencyCode)
				|| preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1
				|| !is_array($fields)
			)
			{
				continue;
			}

			$allowedFields = array_intersect_key($fields, self::ALLOWED_FIELDS);
			if ($allowedFields === [])
			{
				continue;
			}

			$updateResult = $this->mutationService->update($currencyCode, $allowedFields);
			if (!$updateResult->isSuccess())
			{
				$result->addErrors($updateResult->getErrors());
			}
		}

		return $result;
	}
}
