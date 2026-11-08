<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;

final class SavedDeliveryWaiter implements SavedDeliveryWaiterInterface
{
	private const WAIT_MAX_ATTEMPTS = 15;
	private const WAIT_INTERVAL_USEC = 1_000_000;
	private const WAIT_TOTAL_BUDGET_USEC = self::WAIT_MAX_ATTEMPTS * self::WAIT_INTERVAL_USEC;

	public function __construct(
		private readonly int $waitTotalBudgetUsec = self::WAIT_TOTAL_BUDGET_USEC,
		private readonly int $waitIntervalUsec = self::WAIT_INTERVAL_USEC,
	)
	{
	}

	/** @inheritDoc */
	public function getProcessedDeliveryIdBoundary(): int
	{
		$row = DeliveryTable::query()
			->setSelect(['ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (int)$row['ID'] : 0;
	}

	/** @inheritDoc */
	public function wait(string $documentKey, int $processedDeliveryIdBoundary): string
	{
		$deadline = microtime(true) + ($this->waitTotalBudgetUsec / 1_000_000);

		while (true)
		{
			if ($this->wasSavedAfterBoundary($documentKey, $processedDeliveryIdBoundary))
			{
				return self::OUTCOME_SAVED;
			}

			if (microtime(true) >= $deadline)
			{
				return self::OUTCOME_TIMEOUT;
			}

			usleep(max(1, $this->waitIntervalUsec));
		}
	}

	private function wasSavedAfterBoundary(string $documentKey, int $processedDeliveryIdBoundary): bool
	{
		$query = DeliveryTable::query()
			->setSelect(['ID'])
			->where('ID', '>', $processedDeliveryIdBoundary)
			->where('EVENT', WebhookProcessor::EVENT_SAVED)
			->where('STATUS', DeliveryTable::STATUS_DONE)
		;
		$row = (clone $query)
			->where('HOST_DOC_KEY', $documentKey)
			->where('RESULT_OBJECT_ID', '>', 0)
			->setLimit(1)
			->fetch()
		;
		if ($row)
		{
			return true;
		}
		$row = $query
			->whereNull('HOST_DOC_KEY')
			->where('EXTERNAL_HASH', $documentKey)
			->setLimit(1)->fetch();

		return is_array($row);
	}
}
