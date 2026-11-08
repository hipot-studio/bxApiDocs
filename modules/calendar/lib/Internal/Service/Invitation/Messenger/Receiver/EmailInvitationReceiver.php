<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\Invitation\Messenger\Receiver;

use Bitrix\Calendar\Core\Queue\Invitation\EmailInvitationDelivery;
use Bitrix\Calendar\Core\Queue\Invitation\EmailInvitationDeliveryStatus;
use Bitrix\Calendar\Core\Queue\LogContext;
use Bitrix\Calendar\Internal\Service\Invitation\Messenger\Message\EmailInvitationMessage;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Config\QueueConfigRegistry;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Retry\RetryStrategyInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\UserTable;
use Psr\Log\LoggerInterface;
use Throwable;

class EmailInvitationReceiver extends AbstractReceiver
{
	private const LOG_TYPE = 'email_invitation';
	private const INVITATION_NOT_ASSEMBLED_MESSAGE = 'invitation is not assembled';
	private const MAIL_DELIVERY_REFUSED_MESSAGE = 'mail subsystem refused the invitation';
	private const MAIL_DELIVERY_FAILED_MESSAGE = 'invitation delivery failed';
	private const ORGANIZER_NOTIFICATION_FAILED_MESSAGE = 'organizer failure notification failed';
	private const RETRY_DELAY_LOOKUP_FAILED_MESSAGE = 'retry delay lookup failed';
	private const MAX_DELIVERY_ATTEMPTS = 3;

	public function __construct(
		private readonly EmailInvitationDelivery $delivery,
		private readonly LoggerInterface $logger,
	)
	{
	}

	/**
	 * {@inheritDoc}
	 */
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof EmailInvitationMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		try
		{
			$status = $this->delivery->deliver(
				$message->eventId,
				$message->addresserId,
				$message->receiverId,
				$message->type,
				$message->changedFieldKeys,
			);
		}
		catch (Throwable $exception)
		{
			$this->rejectRetryable($message, self::MAIL_DELIVERY_FAILED_MESSAGE, $exception);
		}

		if ($status === EmailInvitationDeliveryStatus::NotAssembled)
		{
			$this->rejectNotAssembled($message);
		}

		if ($status === EmailInvitationDeliveryStatus::Refused)
		{
			$this->rejectRetryable($message, self::MAIL_DELIVERY_REFUSED_MESSAGE);
		}
	}

	/**
	 * @throws UnrecoverableMessageException
	 */
	private function rejectNotAssembled(EmailInvitationMessage $message): void
	{
		$this->logErrorSafely(
			self::INVITATION_NOT_ASSEMBLED_MESSAGE,
			$this->getLogContext($message),
		);
		$this->notifyOrganizerFailureSafely($message);

		throw new UnrecoverableMessageException(self::INVITATION_NOT_ASSEMBLED_MESSAGE);
	}

	/**
	 * Both a refusal and a thrown failure share the same three attempts: left to the broker,
	 * a thrown failure would live for the whole queue ttl and die without the organizer notice.
	 * The broker drops a dead message without calling the receiver again, so the organizer is
	 * notified here, on the last attempt we own.
	 *
	 * @throws RecoverableMessageException
	 * @throws UnrecoverableMessageException
	 */
	private function rejectRetryable(
		EmailInvitationMessage $message,
		string $reason,
		?Throwable $cause = null,
	): void
	{
		$attempt = $message->registerDeliveryAttempt();

		$context = $this->getLogContext($message) + ['attempt' => $attempt];
		if ($cause !== null)
		{
			$context['exception'] = LogContext::fromThrowable($cause);
		}

		$this->logWarningSafely($reason, $context);

		if ($attempt < self::MAX_DELIVERY_ATTEMPTS)
		{
			throw new RecoverableMessageException(
				$reason,
				previous: $cause,
				retryDelay: $this->getRetryDelay($message, $attempt),
			);
		}

		$this->notifyOrganizerFailureSafely($message);

		throw new UnrecoverableMessageException($reason, previous: $cause);
	}

	/**
	 * The broker derives its own delay from the ttl, which our own attempt counter never moves,
	 * so left to it every managed retry would wait the very first interval. The interval comes
	 * from the queue retry strategy, which keeps it configurable in .settings.php.
	 *
	 * @param int $attempt Number of the attempt just spent, starting from one.
	 *
	 * @return int|null Seconds to wait, or null to leave the choice to the broker.
	 */
	private function getRetryDelay(EmailInvitationMessage $message, int $attempt): ?int
	{
		try
		{
			$retryStrategy = $this->getRetryStrategy();
			if ($retryStrategy === null)
			{
				return null;
			}

			return max(1, intdiv($retryStrategy->getWaitingTime($attempt - 1), 1000));
		}
		catch (Throwable $exception)
		{
			$this->logErrorSafely(
				self::RETRY_DELAY_LOOKUP_FAILED_MESSAGE,
				$this->getLogContext($message) + ['attempt' => $attempt, 'exception' => LogContext::fromThrowable($exception)],
			);

			return null;
		}
	}

	protected function getRetryStrategy(): ?RetryStrategyInterface
	{
		if (!isset($this->queueId))
		{
			return null;
		}

		return ServiceLocator::getInstance()
			->get(QueueConfigRegistry::class)
			->getQueueConfig($this->queueId)
			?->retryStrategy
		;
	}

	/**
	 * A delivery stops being retried whatever the notification does: left to escape, a failing
	 * notification would turn the terminal decision into an ordinary failure, and the message
	 * would keep being delivered until the ttl burns out with the organizer never told.
	 */
	private function notifyOrganizerFailureSafely(EmailInvitationMessage $message): void
	{
		try
		{
			$this->notifyOrganizerFailure($message);
		}
		catch (Throwable $exception)
		{
			$this->logErrorSafely(
				self::ORGANIZER_NOTIFICATION_FAILED_MESSAGE,
				$this->getLogContext($message) + ['exception' => LogContext::fromThrowable($exception)],
			);
		}
	}

	protected function notifyOrganizerFailure(EmailInvitationMessage $message): void
	{
		$event = $this->getEventNotificationData($message->eventId);
		$email = $this->getReceiverEmail($message->receiverId);

		$this->sendOrganizerFailureNotification([
			'mode' => 'fail_ical_invite',
			'eventId' => $event['eventId'],
			'userId' => $event['userId'] ?: $message->addresserId,
			'guestId' => $event['userId'] ?: $message->addresserId,
			'items' => [['email' => $email ?? (string)$message->receiverId]],
			'name' => $event['name'] ?? (string)$message->eventId,
			'icalMethod' => $message->type,
		]);
	}

	protected function getEventNotificationData(int $eventId): array
	{
		try
		{
			$event = EventTable::query()
				->setSelect(['ID', 'PARENT_ID', 'NAME', 'MEETING_HOST'])
				->where('ID', $eventId)
				->setLimit(1)
				->fetch()
			;
		}
		catch (Throwable $exception)
		{
			$this->logNotificationRecoveryFailure($eventId, $exception);

			return ['eventId' => $eventId, 'userId' => 0, 'name' => null];
		}

		if (!is_array($event))
		{
			return ['eventId' => $eventId, 'userId' => 0, 'name' => null];
		}

		return [
			'eventId' => (int)$event['PARENT_ID'] ?: (int)$event['ID'],
			'userId' => (int)$event['MEETING_HOST'],
			'name' => (string)$event['NAME'],
		];
	}

	protected function getReceiverEmail(int $receiverId): ?string
	{
		try
		{
			$user = UserTable::query()
				->setSelect(['EMAIL'])
				->where('ID', $receiverId)
				->setLimit(1)
				->fetch()
			;
		}
		catch (Throwable $exception)
		{
			$this->logNotificationRecoveryFailure($receiverId, $exception);

			return null;
		}

		$email = is_array($user) ? trim((string)$user['EMAIL']) : '';

		return $email !== '' ? $email : null;
	}

	protected function sendOrganizerFailureNotification(array $fields): void
	{
		\CCalendarNotify::Send($fields);
	}

	private function logNotificationRecoveryFailure(int $entityId, Throwable $exception): void
	{
		$this->logErrorSafely(
			'notification data recovery failed',
			[
				'type' => self::LOG_TYPE,
				'entityId' => $entityId,
				'exception' => LogContext::fromThrowable($exception),
			],
		);
	}

	/**
	 * The log lives in the database as well, so a failing write must never take the place of the
	 * retry or removal decision the queue is waiting for.
	 */
	private function logErrorSafely(string $message, array $context): void
	{
		try
		{
			$this->logger->error($message, $context);
		}
		catch (Throwable)
		{
		}
	}

	private function logWarningSafely(string $message, array $context): void
	{
		try
		{
			$this->logger->warning($message, $context);
		}
		catch (Throwable)
		{
		}
	}

	private function getLogContext(EmailInvitationMessage $message): array
	{
		return [
			'type' => self::LOG_TYPE,
			'entityId' => $message->eventId,
		];
	}
}
