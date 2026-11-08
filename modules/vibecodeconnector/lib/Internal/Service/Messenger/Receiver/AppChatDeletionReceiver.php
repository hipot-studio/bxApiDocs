<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver;

use Bitrix\Im\V2\Chat\Cleanup\ChatContentCollector;
use Bitrix\Main\Loader;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatLocator;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatRetryPolicy;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\AppChatDeletionMessage;

class AppChatDeletionReceiver extends AbstractReceiver
{
	private const STALLED_DELETION_AUDIT_TYPE = 'VIBECODECONNECTOR_APP_CHAT_DELETION_STALLED';
	private const ABANDONED_DELETION_AUDIT_TYPE = 'VIBECODECONNECTOR_APP_CHAT_DELETION_ABANDONED';
	private const RETRY_MAX_ATTEMPTS = 12;

	public function __construct(
		private readonly AppChatRetryPolicy $retryPolicy = new AppChatRetryPolicy(),
		private readonly ChatLocator $chatLocator = new ChatLocator(),
		private readonly CatalogItemRepository $catalogItemRepository = new CatalogItemRepository(),
	) {}

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof AppChatDeletionMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if (!$this->isMessengerAvailable())
		{
			$this->retry($message, 'IM module is unavailable');

			return;
		}

		if (!$message->onlyIfUnbound)
		{
			$this->deleteOwnedChat($message);

			return;
		}

		$item = $this->catalogItemRepository->getById($message->catalogItemId);
		if ($item !== null && (int)$item->getAppChatId() === $message->chatId)
		{
			return;
		}

		$this->deleteOwnedChat($message);
	}

	private function deleteOwnedChat(AppChatDeletionMessage $message): void
	{
		$chat = $this->chatLocator->find($message->chatId);
		if (
			$chat !== null
			&& (
				$chat->getEntityType() !== AppChatType::ENTITY_TYPE
				|| (string)$chat->getEntityId() !== (string)$message->catalogItemId
			)
		)
		{
			return;
		}

		$this->deleteChat($message->chatId, $message->ownerId);
	}

	protected function isMessengerAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	protected function deleteChat(int $chatId, int $ownerId): void
	{
		(new ChatContentCollector($chatId))->deleteChat($ownerId);
	}

	protected function logStalledDeletion(
		AppChatDeletionMessage $message,
		string $reason,
		int $retryDelay,
	): void {
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_WARNING,
			'AUDIT_TYPE_ID' => self::STALLED_DELETION_AUDIT_TYPE,
			'MODULE_ID' => 'vibecodeconnector',
			'ITEM_ID' => $message->chatId,
			'DESCRIPTION' => "App chat {$message->chatId} of owner {$message->ownerId}: deletion failed "
				. "{$message->attemptCount} times and is retried in {$retryDelay} s: {$reason}",
		]);
	}

	protected function logAbandonedDeletion(AppChatDeletionMessage $message, string $reason): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => self::ABANDONED_DELETION_AUDIT_TYPE,
			'MODULE_ID' => 'vibecodeconnector',
			'ITEM_ID' => $message->chatId,
			'DESCRIPTION' => "App chat {$message->chatId} of owner {$message->ownerId}: deletion gave up "
				. "after {$message->attemptCount} attempts and left the queue, the orphan cleanup of the "
				. "maintenance agent remains responsible for it: {$reason}",
		]);
	}

	private function retry(AppChatDeletionMessage $message, string $reason): void
	{
		$message->attemptCount++;
		if ($message->attemptCount >= self::RETRY_MAX_ATTEMPTS)
		{
			try
			{
				$this->logAbandonedDeletion($message, $reason);
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
				$this->logStalledDeletion($message, $reason, $retryDelay);
			}
			catch (\Throwable)
			{
			}
		}

		throw new RecoverableMessageException(message: $reason, retryDelay: $retryDelay);
	}
}
