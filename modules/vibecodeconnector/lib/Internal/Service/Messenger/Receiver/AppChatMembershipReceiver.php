<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMembershipOutcome;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMembershipPhase;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMembershipService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatRetryPolicy;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\AppChatItemSyncMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\AppChatUserSyncMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\QueueId;

class AppChatMembershipReceiver extends AbstractReceiver
{
	private const STALLED_AUDIT_TYPE = 'VIBECODECONNECTOR_APP_CHAT_MEMBERSHIP_STALLED';
	private const ABANDONED_AUDIT_TYPE = 'VIBECODECONNECTOR_APP_CHAT_MEMBERSHIP_ABANDONED';
	private const SOFT_RETRY_MAX_ATTEMPTS = 12;

	public function __construct(
		private readonly AppChatMembershipService $membershipService = new AppChatMembershipService(),
		private readonly AppChatRetryPolicy $retryPolicy = new AppChatRetryPolicy(),
	) {}

	protected function process(MessageInterface $message): void
	{
		if ($message instanceof AppChatItemSyncMessage)
		{
			$this->syncItem($message);

			return;
		}

		if ($message instanceof AppChatUserSyncMessage)
		{
			$this->syncUser($message);

			return;
		}

		throw new UnprocessableMessageException($message);
	}

	protected function logStalledIntent(string $itemId, string $reason, int $attemptCount, int $retryDelay): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_WARNING,
			'AUDIT_TYPE_ID' => self::STALLED_AUDIT_TYPE,
			'MODULE_ID' => 'vibecodeconnector',
			'ITEM_ID' => $itemId,
			'DESCRIPTION' => "App chat membership of {$itemId}: alignment failed {$attemptCount} times "
				. "and is retried in {$retryDelay} s: {$reason}",
		]);
	}

	protected function logAbandonedIntent(string $itemId, string $reason, int $attemptCount): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => self::ABANDONED_AUDIT_TYPE,
			'MODULE_ID' => 'vibecodeconnector',
			'ITEM_ID' => $itemId,
			'DESCRIPTION' => "App chat membership of {$itemId}: alignment gave up after {$attemptCount} "
				. "attempts and left the queue, maintenance repair remains responsible for it: {$reason}",
		]);
	}

	private function syncItem(AppChatItemSyncMessage $message): void
	{
		$revision = $message->revision > 0
			? $message->revision
			: $this->resolveRevision($message->catalogItemId);

		$outcome = $this->converge(
			$message->catalogItemId,
			$revision,
			$message->afterUserId,
			$message->getPhase(),
		);

		if ($outcome->retryReason !== null)
		{
			$this->softRetry(
				'item.' . $message->catalogItemId,
				$message,
				$outcome->retryReason,
			);

			return;
		}

		if ($outcome->nextPhase !== null && $outcome->nextCursor !== null)
		{
			$this->sendItemContinuation(
				$message->catalogItemId,
				$revision,
				$outcome->nextCursor,
				$outcome->nextPhase,
			);
		}
	}

	private function syncUser(AppChatUserSyncMessage $message): void
	{
		$outcome = $this->convergeUser($message->userId, $message->afterItemId);

		if ($outcome->retryReason !== null)
		{
			$this->softRetry('user.' . $message->userId, $message, $outcome->retryReason);

			return;
		}

		if ($outcome->nextCursor !== null)
		{
			$this->sendUserContinuation($message->userId, $outcome->nextCursor);
		}
	}

	protected function converge(
		int $catalogItemId,
		int $revision,
		int $afterUserId,
		AppChatMembershipPhase $phase,
	): AppChatMembershipOutcome
	{
		return $this->membershipService->applyItemSync($catalogItemId, $revision, $afterUserId, $phase);
	}

	protected function convergeUser(int $userId, int $afterItemId): AppChatMembershipOutcome
	{
		return $this->membershipService->applyUserSync($userId, $afterItemId);
	}

	protected function resolveRevision(int $catalogItemId): int
	{
		return $this->membershipService->resolveSyncRevision($catalogItemId);
	}

	private function softRetry(
		string $itemId,
		AppChatItemSyncMessage|AppChatUserSyncMessage $message,
		string $reason,
	): void {
		$message->attemptCount++;
		if ($message->attemptCount >= self::SOFT_RETRY_MAX_ATTEMPTS)
		{
			try
			{
				$this->logAbandonedIntent($itemId, $reason, $message->attemptCount);
			}
			catch (\Throwable)
			{
			}

			return;
		}

		$retryDelay = $this->retryPolicy->forAttempt($message->attemptCount);
		if ($this->retryPolicy->forAttempt($message->attemptCount + 1) === $retryDelay)
		{
			try
			{
				$this->logStalledIntent($itemId, $reason, $message->attemptCount, $retryDelay);
			}
			catch (\Throwable)
			{
			}
		}

		throw new RecoverableMessageException(message: $reason, retryDelay: $retryDelay);
	}

	private function sendItemContinuation(
		int $catalogItemId,
		int $revision,
		int $cursor,
		AppChatMembershipPhase $phase,
	): void {
		$message = new AppChatItemSyncMessage($catalogItemId, $revision, $cursor, $phase->value);
		$message->send(
			QueueId::AppChatMembership->value,
			[new ItemIdParam('item.' . $catalogItemId)],
		);
	}

	private function sendUserContinuation(int $userId, int $cursor): void
	{
		$message = new AppChatUserSyncMessage($userId, $cursor);
		$message->send(
			QueueId::AppChatMembership->value,
			[new ItemIdParam('user.' . $userId)],
		);
	}
}
