<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\Internal\Integration\Draft\ScheduledMessageDraftGateway;
use Bitrix\Mail\Internals\ScheduledMessageTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Repository\ScheduledMessageRepository;
use Bitrix\Mail\Service\Message\SendLimitChecker;
use Bitrix\Mail\Service\Message\SendMessageCommand;
use Bitrix\Mail\Service\Message\SendMessageService;
use Bitrix\Main\Error;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class ScheduledMessageDispatcher
{
	public const ERROR_SENDER_UNAVAILABLE = ScheduledMessageSenderChecker::ERROR_SENDER_UNAVAILABLE;
	public const ERROR_ATTACHMENT_UNAVAILABLE = ScheduledMessageOutgoingAttachments::ERROR_ATTACHMENT_UNAVAILABLE;
	public const ERROR_SEND_LIMIT = SendLimitChecker::ERROR_SEND_LIMIT;
	public const ERROR_SEND_EXCEPTION = 'send_exception';
	public const ERROR_EXPIRED = 'expired';

	/**
	 * A letter that waited this long past its moment is no longer the letter the author planned, so it is
	 * reported instead of sent. Five automatic attempts with a growing backoff take about two hours, so only
	 * a portal that stood still — agents off, feature turned on after a long pause — reaches the limit.
	 *
	 * The section words its rows by the same border: past it a letter is not leaving, and the row must not
	 * promise a send (`ScheduledMessageTimePresenter`).
	 */
	public const MAX_DELAY_SECONDS = 86400;

	/**
	 * A row left in the sending state this long is a send nobody is running any more, and `recoverStuckSending`
	 * takes it back. Until then the letter cannot leave, so the section must not word such a row as one that is
	 * about to go (`ScheduledMessageTimePresenter`).
	 */
	public const STUCK_SENDING_TIMEOUT = 900;

	private const BATCH_SIZE = 50;
	private const MAX_BATCHES_PER_RUN = 10;
	private const MAX_RUNTIME_SECONDS = 20.0;
	private const MAX_ATTEMPTS = 5;
	private const MAX_BACKOFF_SECONDS = 3600;

	private \Closure $sendMessage;
	private \Closure $createCommand;
	private \Closure $notifyFailure;
	private \Closure $clock;

	/**
	 * `isAuthenticated()` goes to the mail server, so the answer is kept for the whole run: a batch of a
	 * single mailbox costs one handshake instead of one per letter.
	 *
	 * @var array<int, bool>
	 */
	private array $authenticatedMailboxes = [];

	public function __construct(
		private readonly ScheduledMessageService $scheduledMessageService = new ScheduledMessageService(),
		private readonly ScheduledMessageRepository $repository = new ScheduledMessageRepository(),
		?callable $sendMessage = null,
		?callable $createCommand = null,
		?callable $notifyFailure = null,
		private readonly SendLimitChecker $limitChecker = new SendLimitChecker(),
		private readonly ScheduledMessageSenderChecker $senderChecker = new ScheduledMessageSenderChecker(),
		?callable $clock = null,
		private readonly ScheduledMessageLargeAttachmentService $largeAttachmentService = new ScheduledMessageLargeAttachmentService(),
		private readonly ?ScheduledMessageDraftGateway $draftGateway = null,
	)
	{
		$this->sendMessage = $sendMessage !== null
			? \Closure::fromCallable($sendMessage)
			: static fn (SendMessageCommand $command, callable $onQueued): Result => (new SendMessageService())->send(
				$command,
				$onQueued,
			);
		$this->createCommand = $createCommand !== null
			? \Closure::fromCallable($createCommand)
			: $this->buildCommand(...);
		// Only a letter that did not leave is reported: a success is seen in the section itself, and an author
		// who plans regularly would get a notification for every letter of a series.
		$this->notifyFailure = $notifyFailure !== null
			? \Closure::fromCallable($notifyFailure)
			: static function (array $row, string $errorCode): void {
				Notification::dispatchScheduledMessageFailedNotification(
					(int)$row['ID'],
					(int)$row['AUTHOR_ID'],
					(string)$row['SUBJECT'],
					$errorCode,
					isset($row['_FAILURE_VIEW_URL']) ? (string)$row['_FAILURE_VIEW_URL'] : null,
				);
			};
		$this->clock = $clock !== null
			? \Closure::fromCallable($clock)
			: static fn (): float => microtime(true)
		;
	}

	public function dispatch(?DateTime $now = null): Result
	{
		$now ??= new DateTime();
		$result = new Result();
		$processed = 0;
		$deadline = ($this->clock)() + self::MAX_RUNTIME_SECONDS;
		$this->authenticatedMailboxes = [];
		$this->recoverStuckSending($now);

		for ($batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++)
		{
			$batchSize = 0;
			foreach ($this->repository->getDue($now, self::BATCH_SIZE) as $row)
			{
				if (($this->clock)() >= $deadline)
				{
					break 2;
				}
				$batchSize++;
				try
				{
					$isProcessed = $this->dispatchRow($row, $now);
				}
				catch (\Throwable $exception)
				{
					$this->logFailure($exception, 'row', (int)($row['ID'] ?? 0));
					$isProcessed = false;
				}

				if ($isProcessed)
				{
					$processed++;
				}
			}

			if ($batchSize < self::BATCH_SIZE)
			{
				break;
			}
		}

		return $result->setData(['processed' => $processed]);
	}

	/** @param array<string, mixed> $row */
	private function dispatchRow(array $row, DateTime $now): bool
	{
		$claimResult = $this->scheduledMessageService->claim(
			(int)$row['ID'],
			(int)$row['REVISION'],
		);
		if (!$claimResult->isSuccess())
		{
			return false;
		}

		$claimedRow = $claimResult->getData()['scheduledMessage'] ?? null;
		if (!is_array($claimedRow))
		{
			return false;
		}

		$revision = (int)$claimedRow['REVISION'];
		if ($this->isUndoCancellationWindowOpen($claimedRow, $now))
		{
			$this->repository->returnToQueue(
				(int)$claimedRow['ID'],
				$revision,
				$claimedRow['CANCEL_UNTIL'],
			);

			return false;
		}
		if ($this->isTooLate($claimedRow, $now))
		{
			$this->handleFailure(
				$claimedRow,
				$revision,
				new Error('Scheduled message is too late to be sent.', self::ERROR_EXPIRED),
				$now,
			);

			return true;
		}

		if ($this->closeByQueueingState($claimedRow, $revision))
		{
			return true;
		}

		try
		{
			$commandResult = ($this->createCommand)($claimedRow);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($exception, 'prepare', (int)$claimedRow['ID']);
			$commandResult = $this->error(self::ERROR_SEND_EXCEPTION, 'Could not prepare scheduled message.');
		}
		if (!$commandResult->isSuccess())
		{
			$this->handleFailure($claimedRow, $revision, $commandResult->getErrors()[0], $now);

			return true;
		}

		$command = $commandResult->getData()['command'];
		if (!$this->isMailboxAuthenticated((int)$claimedRow['MAILBOX_ID'], $command))
		{
			$this->handleFailure(
				$claimedRow,
				$revision,
				new Error('Mailbox authentication failed.', SendMessageService::ERROR_MAILBOX_UNAVAILABLE),
				$now,
			);

			return true;
		}

		try
		{
			$sendResult = ($this->sendMessage)(
				$command,
				function () use ($claimedRow): void {
					$receiptResult = $this->repository->recordDispatchReceipt((int)$claimedRow['ID']);
					if (!$receiptResult->isSuccess())
					{
						throw new \RuntimeException('Could not record scheduled message dispatch receipt.');
					}
				},
			);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($exception, 'send', (int)$claimedRow['ID']);
			if ($this->closeByQueueingState($this->withCurrentReceipt($claimedRow), $revision))
			{
				return true;
			}

			$sendResult = $this->error(self::ERROR_SEND_EXCEPTION, 'Could not send scheduled message.');
		}
		if ($sendResult->isSuccess())
		{
			$this->repository->markSent((int)$claimedRow['ID'], $revision);

			return true;
		}

		$this->handleFailure($claimedRow, $revision, $sendResult->getErrors()[0], $now);

		return true;
	}

	/** @param array<string, mixed> $row */
	private function isUndoCancellationWindowOpen(array $row, DateTime $now): bool
	{
		$cancelUntil = $row['CANCEL_UNTIL'] ?? null;

		return ($row['MODE'] ?? null) === ScheduledMessageTable::MODE_UNDO
			&& $cancelUntil instanceof DateTime
			&& $cancelUntil->getTimestamp() > $now->getTimestamp()
		;
	}

	/** @param array<string, mixed> $row */
	private function handleFailure(array $row, int $revision, Error $error, DateTime $now): void
	{
		$attempts = (int)$row['ATTEMPTS'] + 1;
		$retryable = $this->isRetryable((string)$error->getCode());
		$errorData = [
			'code' => (string)$error->getCode(),
			'message' => $error->getMessage(),
			'retryable' => $retryable,
		];

		if ($retryable && $attempts < self::MAX_ATTEMPTS)
		{
			$nextAttemptAt = DateTime::createFromTimestamp(
				$now->getTimestamp() + $this->backoffSeconds($attempts),
			);
			$this->repository->markForRetry(
				(int)$row['ID'],
				$revision,
				$attempts,
				$nextAttemptAt,
				$errorData,
			);

			return;
		}

		$this->markFinalError($row, $revision, $attempts, $errorData);
	}

	private function isMailboxAuthenticated(int $mailboxId, SendMessageCommand $command): bool
	{
		if ($command->mailbox === null)
		{
			return true;
		}

		return $this->authenticatedMailboxes[$mailboxId] ??= $command->mailbox->isAuthenticated();
	}

	private function recoverStuckSending(DateTime $now): void
	{
		$updatedBefore = DateTime::createFromTimestamp($now->getTimestamp() - self::STUCK_SENDING_TIMEOUT);
		foreach ($this->repository->getStuckSending($updatedBefore, self::BATCH_SIZE) as $row)
		{
			if (!$this->closeByQueueingState($row, (int)$row['REVISION']))
			{
				$this->repository->returnToQueue((int)$row['ID'], (int)$row['REVISION'], $now);
			}
		}
	}

	private function isRetryable(string $code): bool
	{
		return in_array($code, [
			SendMessageService::ERROR_SEND_FAILED,
			SendMessageService::ERROR_MAILBOX_UNAVAILABLE,
			self::ERROR_SEND_LIMIT,
			self::ERROR_SEND_EXCEPTION,
		], true);
	}

	/**
	 * The delay is counted from the later of the two moments. `SCHEDULED_AT` alone would refuse a letter the
	 * author has just sent again by hand: a manual retry moves `NEXT_ATTEMPT_AT` to now and leaves the old
	 * moment of the plan where it was.
	 *
	 * @param array<string, mixed> $row
	 */
	private function isTooLate(array $row, DateTime $now): bool
	{
		$dueAt = 0;
		foreach ([$row['SCHEDULED_AT'] ?? null, $row['NEXT_ATTEMPT_AT'] ?? null] as $moment)
		{
			if ($moment instanceof DateTime)
			{
				$dueAt = max($dueAt, $moment->getTimestamp());
			}
		}

		return $dueAt > 0 && $now->getTimestamp() - $dueAt > self::MAX_DELAY_SECONDS;
	}

	/**
	 * The receipt of the row is the whole proof that a letter was handed over for delivery, and a row without
	 * one is a letter that has still to be sent. `Mailbox::mail()` writes the row of the upload queue and this
	 * receipt inside one transaction, so a letter owns either both of them or neither, and
	 * `Mailbox::syncOutgoing()` takes to the server only the letters that own a row in that queue. Whatever
	 * else an interrupted queueing managed to leave behind — a copy of the letter among the messages of the
	 * mailbox, say — no recipient is waiting for a letter that kept no receipt, and only the sending of it
	 * makes the letter leave.
	 *
	 * A letter that does own the receipt has been queued once already, and queueing it a second time would
	 * leave the recipient with two copies of it, so the row is closed as sent instead of being offered to the
	 * sender again. The question is asked wherever a row may carry that receipt from an earlier run: before an
	 * attempt, after an attempt that broke off, and over a row left in the sending state.
	 *
	 * @param array<string, mixed> $row
	 */
	private function closeByQueueingState(array $row, int $revision): bool
	{
		if (($row['DISPATCH_RECEIPT_AT'] ?? null) === null)
		{
			return false;
		}

		$this->repository->markSent((int)$row['ID'], $revision);

		return true;
	}

	/**
	 * The receipt as the row holds it now, for a question about the attempt of this very run: the queueing of
	 * that attempt may have committed a moment before the failure, and the row claimed before it cannot know.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function withCurrentReceipt(array $row): array
	{
		return array_replace($row, [
			'DISPATCH_RECEIPT_AT' => $this->repository->readDispatchReceipt((int)$row['ID']),
		]);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array{code: string, message: string, retryable: bool} $error
	 */
	private function markFinalError(array $row, int $revision, int $attempts, array $error): void
	{
		if ((string)($row['MODE'] ?? '') === ScheduledMessageTable::MODE_UNDO)
		{
			$markResult = $this->getDraftGateway()->restoreAfterUndoFailure(
				$row,
				$revision,
				$attempts,
				$error,
			);
			$draftId = (int)($markResult->getData()['draftId'] ?? 0);
			if ($markResult->isSuccess() && $draftId > 0)
			{
				$row['_FAILURE_VIEW_URL'] = $this->draftRestoreUrl(
					(int)($row['MAILBOX_ID'] ?? 0),
					$draftId,
				);
			}
		}
		else
		{
			$markResult = $this->repository->markError(
				(int)$row['ID'],
				$revision,
				$attempts,
				$error,
			);
		}

		if ($markResult->isSuccess())
		{
			$this->notifyFailureSafely($row, $error['code']);
		}
	}

	private function backoffSeconds(int $attempts): int
	{
		return min(60 * (2 ** max(0, $attempts - 1)), self::MAX_BACKOFF_SECONDS);
	}

	/** @param array<string, mixed> $row */
	private function buildCommand(array $row): Result
	{
		$authorId = (int)$row['AUTHOR_ID'];
		$mailboxId = (int)$row['MAILBOX_ID'];
		$sender = $this->formatAddress((array)$row['SENDER']);
		if ($sender === null)
		{
			return $this->error(ScheduledMessageService::ERROR_INVALID_HEADER, 'Mail header contains invalid characters.');
		}
		$senderAddress = new Address($sender);
		if (!$senderAddress->validate())
		{
			return $this->error(self::ERROR_SENDER_UNAVAILABLE, 'Sender is not available.');
		}

		// The address is held to the list the form offered and the immediate sending holds it to: an alias and a
		// confirmed sender of the author leave as well, through the mailbox the letter was planned with.
		$senderResult = $this->senderChecker->check($authorId, $senderAddress->getEmail());
		if (!$senderResult->isSuccess())
		{
			return $senderResult;
		}

		// The letter leaves through the mailbox it was planned with and through no other: an address of the
		// author may belong to several of them, and the lookup by address alone would pick the wrong one.
		$mailboxRow = MailboxTable::getUserMailbox($mailboxId, $authorId);
		$mailbox = Mailbox::findBy($mailboxId, null, $authorId);
		if (!is_array($mailboxRow) || $mailbox === null)
		{
			return $this->error(
				ScheduledMessageService::ERROR_MAILBOX_ACCESS_DENIED,
				'Mailbox is not available to the author.',
			);
		}

		$recipients = (array)$row['RECIPIENTS'];
		$toResult = $this->formatAddresses((array)($recipients['to'] ?? []));
		$ccResult = $this->formatAddresses((array)($recipients['cc'] ?? []));
		$bccResult = $this->formatAddresses((array)($recipients['bcc'] ?? []));
		foreach ([$toResult, $ccResult, $bccResult] as $addressResult)
		{
			if (!$addressResult->isSuccess())
			{
				return $addressResult;
			}
		}
		$to = (array)$toResult->getData()['addresses'];
		$cc = (array)$ccResult->getData()['addresses'];
		$bcc = (array)$bccResult->getData()['addresses'];
		$limitResult = $this->limitChecker->check($senderAddress->getEmail(), $to, $cc, $bcc);
		if (!$limitResult->isSuccess())
		{
			return $limitResult;
		}

		$largeAttachmentResult = $this->largeAttachmentService->prepare(
			$authorId,
			(array)($row['LARGE_ATTACHMENTS'] ?? []),
			(array)($row['ATTACHMENTS'] ?? []),
			(string)$row['BODY'],
		);
		if (!$largeAttachmentResult->isSuccess())
		{
			return $largeAttachmentResult;
		}
		$largeAttachmentData = $largeAttachmentResult->getData();
		$largeSourceIds = array_fill_keys((array)($largeAttachmentData['sourceFileIds'] ?? []), true);
		$outgoingAttachmentRows = array_values(array_filter(
			(array)($row['ATTACHMENTS'] ?? []),
			static fn (array $attachment): bool => !isset(
				$largeSourceIds[(int)($attachment['SOURCE_OBJECT_ID'] ?? 0)],
			),
		));

		$attachmentResult = (new ScheduledMessageOutgoingAttachments($this->hostname()))
			->prepare($outgoingAttachmentRows)
		;
		if (!$attachmentResult->isSuccess())
		{
			return $attachmentResult;
		}
		$attachmentData = $attachmentResult->getData();

		$result = new Result();
		$result->setData([
			'command' => new SendMessageCommand(
				authorId: $authorId,
				mailbox: $mailbox,
				sender: $sender,
				recipients: $to,
				cc: $cc,
				bcc: $bcc,
				subject: (string)$row['SUBJECT'],
				body: ScheduledMessageBody::forOutgoing(
					(string)$row['BODY'],
					(array)($attachmentData['contentIds'] ?? []),
				),
				attachments: (array)($attachmentData['attachments'] ?? []),
				messageId: $this->messageId((int)$row['ID']),
				inReplyTo: isset($row['IN_REPLY_TO']) ? (string)$row['IN_REPLY_TO'] : null,
				externalLinkIds: (array)($largeAttachmentData['externalLinkIds'] ?? []),
			),
		]);

		return $result;
	}

	/** @param array<int, array<string, mixed>|string> $items */
	private function formatAddresses(array $items): Result
	{
		$result = [];
		foreach ($items as $item)
		{
			$address = is_array($item) ? $this->formatAddress($item) : $this->normalizeAddress((string)$item);
			if ($address === null)
			{
				return $this->error(
					ScheduledMessageService::ERROR_INVALID_HEADER,
					'Mail header contains invalid characters.',
				);
			}

			$result[] = $address;
		}

		return (new Result())->setData(['addresses' => array_values(array_unique($result))]);
	}

	/** @param array<string, mixed> $data */
	private function formatAddress(array $data): ?string
	{
		$email = (string)($data['email'] ?? '');
		$name = trim((string)($data['name'] ?? ''));

		return $this->normalizeAddress($name === '' ? $email : sprintf('%s <%s>', $name, $email));
	}

	private function normalizeAddress(string $value): ?string
	{
		if (preg_match('/[\x00-\x1F\x7F]/', $value))
		{
			return null;
		}

		$address = new Address($value);

		return $address->validate() ? $address->getEncoded() : null;
	}

	private function messageId(int $id): string
	{
		return sprintf('<bx.mail.scheduled.%d@%s>', $id, $this->hostname());
	}

	private function hostname(): string
	{
		return defined('BX24_HOST_NAME') && BX24_HOST_NAME !== ''
			? BX24_HOST_NAME
			: (\COption::getOptionString('main', 'server_name', '') ?: 'localhost');
	}

	private function draftRestoreUrl(int $mailboxId, int $draftId): string
	{
		return $mailboxId > 0 && $draftId > 0
			? sprintf('/mail/message/new?id=%d&draftId=%d', $mailboxId, $draftId)
			: ''
		;
	}

	private function getDraftGateway(): ScheduledMessageDraftGateway
	{
		return $this->draftGateway ?? new ScheduledMessageDraftGateway($this->repository);
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	/** @param array<string, mixed> $row */
	private function notifyFailureSafely(array $row, string $errorCode): void
	{
		try
		{
			($this->notifyFailure)($row, $errorCode);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($exception, 'notify', (int)($row['ID'] ?? 0));
		}
	}

	private function logFailure(\Throwable $exception, string $stage, int $id): void
	{
		try
		{
			(new \Bitrix\Main\Diag\LoggerFactory())->createById(
				'mail.scheduled_message',
				[],
				false,
			)?->error(
				'Scheduled message processing failed at {stage} for #{id}: {exceptionClass}',
				[
					'stage' => $stage,
					'id' => $id,
					'exceptionClass' => $exception::class,
				],
			);
		}
		catch (\Throwable)
		{
		}
	}
}
