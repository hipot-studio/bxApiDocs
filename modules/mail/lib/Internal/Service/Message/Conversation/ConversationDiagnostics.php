<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message\Conversation;

use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * The single writer of the diagnostic signals of the conversation grid.
 *
 * No method here can receive the content of a letter: every parameter is an identifier, a counter or
 * a duration, so a subject, an address or a body cannot reach the log even by mistake. Consumers that
 * need to observe the signals substitute this class instead of reading the log.
 *
 * Two loggers are used on purpose. Failures and the readiness of a mailbox are written whatever the
 * portal configured, the way the rest of the module reports its failures. The measurements of the
 * read path are asked for by their identifier, so a portal that does not watch the feature does not
 * pay a log record per request of the grid.
 */
class ConversationDiagnostics
{
	private const LOGGER_ID = 'mail.Conversation';

	private ?LoggerInterface $failureLogger = null;
	private ?LoggerInterface $measurementLogger = null;

	/** The projection of one message could not be written. */
	public function indexerFailed(int $mailboxId, int $messageId, string $errorCode): void
	{
		$this->failureLogger()->error('Mail conversation projection of a message failed.', [
			'mailboxId' => $mailboxId,
			'messageId' => $messageId,
			'errorCode' => $errorCode,
		]);
	}

	/** One page of the grid has been served. */
	public function listServed(int $mailboxId, int $durationMs, int $rowCount): void
	{
		$this->measurementLogger()?->info('Mail conversation list served.', [
			'mailboxId' => $mailboxId,
			'durationMs' => $durationMs,
			'rowCount' => $rowCount,
		]);
	}

	/** One portion of an expanded row has been served. */
	public function itemsServed(int $mailboxId, int $conversationId, int $durationMs, int $itemCount): void
	{
		$this->measurementLogger()?->info('Mail conversation items served.', [
			'mailboxId' => $mailboxId,
			'conversationId' => $conversationId,
			'durationMs' => $durationMs,
			'itemCount' => $itemCount,
		]);
	}

	/**
	 * The backfill has walked the whole mailbox and reconciled the result.
	 *
	 * @param int $pendingCount messages of the mailbox the projection does not hold yet
	 * @param int $staleCount members of the projection whose message is gone
	 * @param int $incompleteCount conversations holding a message without a live uid row
	 * @param int $failedCount messages the pass could not project
	 * @param bool $ready whether the mailbox has just been allowed to group its list
	 */
	public function passFinished(
		int $mailboxId,
		int $pendingCount,
		int $staleCount,
		int $incompleteCount,
		int $failedCount,
		bool $ready,
	): void
	{
		$context = [
			'mailboxId' => $mailboxId,
			'pendingCount' => $pendingCount,
			'staleCount' => $staleCount,
			'incompleteCount' => $incompleteCount,
			'failedCount' => $failedCount,
		];

		if ($ready)
		{
			$this->failureLogger()->notice('Mail conversation projection of a mailbox is ready.', $context);

			return;
		}

		$this->failureLogger()->warning('Mail conversation projection of a mailbox stays unready.', $context);
	}

	/** A conversation was too large to split inline and requires a background rebuild. */
	public function conversationRebuildRequired(int $mailboxId, int $conversationId, int $participantLimit): void
	{
		$this->failureLogger()->warning('Mail conversation requires a background rebuild after a removal.', [
			'mailboxId' => $mailboxId,
			'conversationId' => $conversationId,
			'participantLimit' => $participantLimit,
		]);
	}

	/** The projection of a mailbox has been dropped, and what the check found afterwards. */
	public function projectionCleared(int $mailboxId, int $leftoverMembers, int $leftoverConversations): void
	{
		$context = [
			'mailboxId' => $mailboxId,
			'leftoverMembers' => $leftoverMembers,
			'leftoverConversations' => $leftoverConversations,
		];

		if ($leftoverMembers === 0 && $leftoverConversations === 0)
		{
			$this->measurementLogger()?->info('Mail conversation projection of a mailbox is cleared.', $context);

			return;
		}

		$this->failureLogger()->error('Mail conversation projection of a mailbox is not fully cleared.', $context);
	}

	private function failureLogger(): LoggerInterface
	{
		$this->failureLogger ??= (new LoggerFactory())->createById(self::LOGGER_ID, [], false);

		return $this->failureLogger;
	}

	private function measurementLogger(): ?LoggerInterface
	{
		$this->measurementLogger ??= (new LoggerFactory())->createById(self::LOGGER_ID);

		return $this->measurementLogger;
	}
}
