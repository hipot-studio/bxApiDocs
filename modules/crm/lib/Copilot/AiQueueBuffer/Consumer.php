<?php

namespace Bitrix\Crm\Copilot\AiQueueBuffer;

use Bitrix\Crm\Copilot\AiQueueBuffer\Controller\AiQueueBufferController;
use Bitrix\Crm\Copilot\AiQueueBuffer\Entity\AiQueueBuffer;
use Bitrix\Crm\Copilot\AiQueueBuffer\Entity\AiQueueBufferItem;
use Bitrix\Crm\Copilot\AiQueueBuffer\Enum\Status;
use Bitrix\Crm\Copilot\Restriction\ExecutionDataManager;
use Bitrix\Crm\Copilot\Restriction\LimitManager;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Result;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Error;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use COption;
use Throwable;

final class Consumer
{
	use Singleton;

	private const LIMIT = 20;
	private const MAX_RETRY_COUNT = 5;
	private const MAX_PENDING_RECORDS = 40;

	private ?array $executionData = null;
	private ?AiQueueBufferController $controller = null;

	public function execute(): void
	{
		$this->init();

		if (!$this->canExecute())
		{
			return;
		}

		$items = $this->getItems();

		if ($items->isEmpty())
		{
			return;
		}

		$this->markItemsAsProgress($items);

		$this->processItems($items);
	}

	private function init(): void
	{
		$this->controller = AiQueueBufferController::getInstance();
	}

	private function canExecute(): bool
	{
		if ($this->isQueueHasManyPendingRecords())
		{
			return false;
		}

		$lastExecutionMonth = $this->getLastExecutionDate()->format('n');
		$currentMonth = (new Date())->format('n');

		if ($currentMonth !== $lastExecutionMonth)
		{
			$this->startNewMonth();

			return true;
		}

		$isPeriodLimitExceeded = LimitManager::getInstance()->isPeriodLimitExceeded();

		return !$isPeriodLimitExceeded;
	}

	private function isQueueHasManyPendingRecords(): bool
	{
		// Call transcription is a long-running (minutes) async job that a single buffer pass can
		// enqueue in bulk (repeat-sale step I.5). Counting those PENDING rows here would let a night
		// batch of transcriptions trip the throttle and stall the whole buffer - including items whose
		// transcripts are already ready. Transcription volume is bounded by its own per-client limit,
		// so it is excluded from the buffer back-pressure counter.
		$count = (int)QueueTable::query()
			->where('EXECUTION_STATUS', QueueTable::EXECUTION_STATUS_PENDING)
			->where('CREATED_TIME', '>=', (new DateTime())->add('-1 day'))
			->where('TYPE_ID', '!=', TranscribeCallRecording::TYPE_ID)
			->queryCountTotal()
		;

		return $count >= self::MAX_PENDING_RECORDS;
	}

	private function getItems(): Entity\EO_AiQueueBuffer_Collection
	{
		return $this->controller->getList([
			'limit' => $this->getLimit(),
		]);
	}

	private function markItemsAsProgress(Entity\EO_AiQueueBuffer_Collection $items): void
	{
		/**
		 * @var $item AiQueueBuffer
		 */
		foreach ($items as $item)
		{
			if ($item->getStatus() !== Status::Progress->value)
			{
				$item->setStatus(Status::Progress->value);
			}
		}

		$items->save();
	}

	private function processItems(Entity\EO_AiQueueBuffer_Collection $items): Result
	{
		$successCount = 0;
		$processedItemIds = [];

		try
		{
			/**
			 * @var $item AiQueueBuffer
			 */
			foreach ($items as $item)
			{
				// the item is charged to this pass before any work on it starts: whatever happens next,
				// the pass must not leave it behind, and must not drop the items it has not reached yet
				$processedItemIds[] = $item->getId();

				try
				{
					if ($this->processItem($item))
					{
						$successCount++;
					}
				}
				catch (Throwable $exception)
				{
					// one broken item must not cancel the rest of the pass
					AIManager::logger()->critical(
						'{date}: {class}: Unexpected error while processing buffer item {itemId}'
						. ' by provider {providerId}: {exception}' . PHP_EOL,
						[
							'class' => self::class,
							'itemId' => $item->getId(),
							'providerId' => $item->getProviderId(),
							'exception' => $exception,
						],
					);

					try
					{
						$this->moveItemToEndOfQueue($item);
					}
					catch (Throwable $moveException)
					{
						AIManager::logger()->critical(
							'{date}: {class}: Failed to move buffer item {itemId} to the end of the queue:'
							. ' {exception}' . PHP_EOL,
							[
								'class' => self::class,
								'itemId' => $item->getId(),
								'exception' => $moveException,
							],
						);
					}
				}
			}
		}
		finally
		{
			if (!empty($processedItemIds))
			{
				$this->controller->delete($processedItemIds);
			}
		}

		return (new Result())->setData(['successCount' => $successCount]);
	}

	/**
	 * Returns true when the provider has finished the item, false when the item was skipped or
	 * deferred. Every failure mode of a single item stays inside the caller's per-item catch.
	 */
	private function processItem(AiQueueBuffer $item): bool
	{
		if ($item->getRetryCount() > self::MAX_RETRY_COUNT)
		{
			return false;
		}

		$provider = Factory::getProvider($item->getProviderId());
		if ($provider === null)
		{
			return false;
		}

		// the last processing attempt before the item is dropped: providers that gate on a pending
		// async prerequisite (repeat-sale transcription) must proceed with what is ready here
		// instead of deferring forever (partial success)
		$isFinalAttempt = $item->getRetryCount() >= self::MAX_RETRY_COUNT;

		$result = $provider->process($item->getProviderData(), $isFinalAttempt);

		if ($result->isSuccess())
		{
			return true;
		}

		if ($this->isNeedMoveItemToEndOfQueue($result->getError()))
		{
			$this->moveItemToEndOfQueue($item);
		}

		return false;
	}

	private function isNeedMoveItemToEndOfQueue(?Error $error = null): bool
	{
		return (
			!in_array(
				$error?->getCode(),
				[
					ErrorCode::INVALID_ARG_VALUE,
					ErrorCode::JOB_ALREADY_EXISTS,
					ErrorCode::NOT_SUITABLE_TARGET,
				],
			true
			)
		);
	}

	private function moveItemToEndOfQueue(AiQueueBuffer $item): void
	{
		$this->controller->delete([$item->getId()]);

		$aiQueueBufferItem = AiQueueBufferItem::createFromEntity($item);
		$aiQueueBufferItem->incrementRetryCount();

		$addResult = $this->controller->add($aiQueueBufferItem);
		if (!$addResult->isSuccess())
		{
			AIManager::logger()->critical(
				'{date}: {class}: Buffer item {itemId} of provider {providerId} is lost: it was removed'
				. ' from the queue, but the retry row was not added: {errors}' . PHP_EOL,
				[
					'class' => self::class,
					'itemId' => $item->getId(),
					'providerId' => $item->getProviderId(),
					'errors' => $addResult->getErrors(),
				],
			);
		}
	}

	private function getLimit(): int
	{
		return COption::GetOptionInt(
			'crm',
			'ai_queue_buffer_execute_limit',
			self::LIMIT
		);
	}

	private function getLastExecutionDate(): Date
	{
		return Date::createFromTimestamp($this->getCurrentPeriodTimestamp());
	}

	private function startNewMonth(): void
	{
		$this->truncateQueue();
		$this->saveNewPeriodTimestamp();

		ExecutionDataManager::getInstance()->clearExecutionData();
	}

	private function truncateQueue(): void
	{
		$this->controller->deleteAll();
	}

	private function getCurrentPeriodTimestamp(): int
	{
		return COption::GetOptionInt(
			'crm',
			'ai_queue_buffer_execution_period_date',
			(new Date())->getTimestamp()
		);
	}

	private function saveNewPeriodTimestamp(): void
	{
		COption::SetOptionInt(
			'crm',
			'ai_queue_buffer_execution_period_date',
			(new Date())->getTimestamp()
		);
	}
}
