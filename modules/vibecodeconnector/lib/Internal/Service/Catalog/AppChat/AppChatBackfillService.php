<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\AppChatBackfill;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

class AppChatBackfillService
{
	private const LOCK_NAME = 'vibecodeconnector.app_chat.backfill';
	private const RETRY_MAX_ATTEMPTS = 12;
	private const ABANDONED_AUDIT_TYPE = 'VIBECODECONNECTOR_APP_CHAT_BACKFILL_ABANDONED';

	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AppChatMembershipService $membershipService = new AppChatMembershipService(),
		private readonly AppChatBackfillCursorStorage $cursorStorage = new AppChatBackfillCursorStorage(),
		private readonly AppChatAvailability $availability = new AppChatAvailability(),
	) {}

	public function runSlice(): void
	{
		if (
			!$this->availability->isEnabled()
			|| !Feature::isEnabled(AppChatBackfill::class)
			|| !$this->acquireLock()
		)
		{
			return;
		}

		try
		{
			$this->runLockedSlice();
		}
		finally
		{
			$this->releaseLock();
		}
	}

	protected function acquireLock(): bool
	{
		return Application::getConnection()->lock(self::LOCK_NAME, 0);
	}

	protected function releaseLock(): void
	{
		Application::getConnection()->unlock(self::LOCK_NAME);
	}

	private function runLockedSlice(): void
	{
		$cursor = $this->cursorStorage->load();
		if ($cursor->isCompleted)
		{
			return;
		}

		if ($cursor->catalogItemId === 0)
		{
			$itemIds = $this->itemRepository->getLiveAppItemIdsAfter($cursor->scanAfterItemId, 1);
			if ($itemIds === [])
			{
				$this->cursorStorage->save($cursor->completeScan());

				return;
			}

			$cursor = $cursor->activate($itemIds[0]);
			$this->cursorStorage->save($cursor);
		}

		try
		{
			$outcome = $this->applyStep($cursor);
		}
		catch (\Throwable $exception)
		{
			$this->retryGlobally(
				$cursor,
				'unexpected membership failure: ' . $exception->getMessage(),
			);

			return;
		}
		if ($outcome->retryReason !== null)
		{
			if ($outcome->retryScope === AppChatMembershipRetryScope::GlobalTransient)
			{
				$this->retryGlobally($cursor, $outcome->retryReason);
			}
			else
			{
				$this->retryOrAbandon($cursor, $outcome->retryReason);
			}

			return;
		}
		if ($outcome->restartRequired)
		{
			$this->cursorStorage->save($cursor->restartWithChat($outcome->chatId));

			return;
		}

		if ($outcome->nextPhase !== null || $outcome->nextCursor !== null)
		{
			if ($outcome->nextPhase === null || $outcome->nextCursor === null)
			{
				$this->retryOrAbandon($cursor, 'the membership step returned an incomplete continuation');

				return;
			}

			$this->cursorStorage->save($cursor->continueWith(
				$outcome->nextPhase,
				$outcome->nextCursor,
				$outcome->chatId,
			));

			return;
		}

		$this->cursorStorage->save($cursor->completeActiveItem());
	}

	protected function applyStep(AppChatBackfillCursor $cursor): AppChatMembershipOutcome
	{
		return $this->membershipService->applyBackfillStep(
			$cursor->catalogItemId,
			$cursor->afterUserId,
			$cursor->phase,
			$cursor->chatId,
		);
	}

	private function retryGlobally(AppChatBackfillCursor $cursor, string $reason): void
	{
		$this->cursorStorage->save($cursor);
		$this->logRetry($cursor, $reason);
	}

	private function retryOrAbandon(AppChatBackfillCursor $cursor, string $reason): void
	{
		$retried = $cursor->recordRetry();
		if ($retried->retryAttempts >= self::RETRY_MAX_ATTEMPTS)
		{
			$this->cursorStorage->save($retried->completeActiveItem());
			$this->logAbandoned($retried, $reason);

			return;
		}

		$this->cursorStorage->save($retried);
		$this->logRetry($retried, $reason);
	}

	private function logRetry(AppChatBackfillCursor $cursor, string $reason): void
	{
		try
		{
			\AddMessage2Log(
				'Catalog item ' . $cursor->catalogItemId
					. ': app-chat backfill retry at ' . $cursor->phase->value
					. '/' . $cursor->afterUserId . ', attempt ' . $cursor->retryAttempts
					. ', ' . $reason,
				'vibecodeconnector',
			);
		}
		catch (\Throwable)
		{
		}
	}

	private function logAbandoned(AppChatBackfillCursor $cursor, string $reason): void
	{
		try
		{
			\CEventLog::Add([
				'SEVERITY' => \CEventLog::SEVERITY_ERROR,
				'AUDIT_TYPE_ID' => self::ABANDONED_AUDIT_TYPE,
				'MODULE_ID' => 'vibecodeconnector',
				'ITEM_ID' => $cursor->catalogItemId,
				'DESCRIPTION' => 'App-chat backfill gave up after ' . $cursor->retryAttempts
					. ' attempts at ' . $cursor->phase->value . '/' . $cursor->afterUserId
					. ' and advanced to the next catalog item: ' . $reason,
			]);
		}
		catch (\Throwable)
		{
		}
	}
}
