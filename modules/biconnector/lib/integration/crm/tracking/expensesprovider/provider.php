<?php

namespace Bitrix\BIConnector\Integration\Crm\Tracking\ExpensesProvider;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Seo;

final class Provider
{
	private Seo\Analytics\Account $account;

	public function __construct(
		protected readonly int $id,
		protected readonly string $name,
		protected readonly string $seoCode,
		protected readonly ?string $accountId,
		protected readonly ?string $clientId
	)
	{
		$service = Seo\Analytics\Service::getInstance()->setClientId($this->clientId);
		$this->account = $service::getAccount($this->seoCode);
	}

	/**
	 * Result data: array<array{
	 *       SOURCE_ID: int,
	 *       EXPENSES: float,
	 *       DATE?: ?Date,
	 *       CAMPAIGN_NAME: string,
	 *       CAMPAIGN_ID: string,
	 *       CURRENCY: string,
	 *       CPM: float,
	 *       CPC: float,
	 *       CLICKS: int,
	 *       IMPRESSIONS: int,
	 *       ACTIONS: int,
	 *   }>
	 *
	 * @return Result
	 */
	public function getDailyExpenses(?Date $dateFrom, ?Date $dateTo): Result
	{
		$result = new Result();
		if ($this->account->hasAccounts() && !$this->accountId)
		{
			return $result->setData([]);
		}

		if (!$this->account->hasDailyExpensesReport())
		{
			return $result->setData([]);
		}

		Seo\Analytics\Service::getInstance()->setClientId($this->clientId);
		$accountResult = $this->account->getDailyExpensesReport($this->accountId, $dateFrom, $dateTo);
		if (!$accountResult->isSuccess())
		{
			$result->addErrors($accountResult->getErrors());

			return $result;
		}

		/** @var Seo\Analytics\Internals\ExpensesCollection $expensesCollection */
		$expensesCollection = $accountResult->getData()['expenses'] ?? null;
		if (!$expensesCollection instanceof Seo\Analytics\Internals\ExpensesCollection)
		{
			$collectionClassName = get_class($expensesCollection);
			$error = new Error("[{$this->name}] Daily expenses result data expected 'Bitrix\Seo\Analytics\Internals\ExpensesCollection' instance, '{$collectionClassName}' got");
			$result->addError($error);

			return $result;
		}

		$expensesResult = [];
		/** @var Seo\Analytics\Internals\Expenses $expenses */
		foreach ($expensesCollection as $expenses)
		{
			$expensesResult[] = $this->parseRow($expenses);
		}

		return $result->setData($expensesResult);
	}

	/**
	 * @param Seo\Analytics\Internals\Expenses $row
	 * @return array{
	 *     SOURCE_ID: int,
	 *     EXPENSES: float,
	 *     DATE?: ?Date,
	 *     CAMPAIGN_NAME: string,
	 *     CAMPAIGN_ID: string,
	 *     CURRENCY: string,
	 *     CPM: float,
	 *     CPC: float,
	 *     CLICKS: int,
	 *     IMPRESSIONS: int,
	 *     ACTIONS: int,
	 * }
	 */
	private function parseRow(Seo\Analytics\Internals\Expenses $row): array
	{
		return [
			'SOURCE_ID' => $this->id,
			'EXPENSES' => $row->getSpend(),
			'DATE' => $row->getDate(),
			'CAMPAIGN_NAME' => $row->getCampaignName(),
			'CAMPAIGN_ID' => $row->getCampaignId(),
			'CURRENCY' => $row->getCurrency(),
			'CPM' => $row->getCpm(),
			'CPC' => $row->getCpc(),
			'CLICKS' => $row->getClicks(),
			'IMPRESSIONS' => $row->getImpressions(),
			'ACTIONS' => $row->getActions(),
		];
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @return string[]
	 */
	public function getUtmSources(): array
	{
		return \Bitrix\Crm\Tracking\Internals\SourceFieldTable::getSourceField(
			$this->getId(),
			\Bitrix\Crm\Tracking\Internals\SourceFieldTable::FIELD_UTM_SOURCE
		);
	}
}
