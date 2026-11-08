<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\Invitation\Messenger\Sender;

use Bitrix\Calendar\Core\Queue\LogContext;
use Bitrix\Calendar\Internal\Service\Invitation\Exception\InvitationSendingException;
use Bitrix\Calendar\Internal\Service\Invitation\Messenger\Message\EmailInvitationMessage;
use Bitrix\Calendar\Internal\Service\Invitation\Messenger\Queue;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Psr\Log\LoggerInterface;
use Throwable;

class EmailInvitationSender
{
	private const LOG_TYPE = 'email_invitation';

	public function __construct(private readonly LoggerInterface $logger)
	{
	}

	/**
	 * Accepts the legacy invitation shape produced by InvitationInfo::toArray().
	 * An invitation without mandatory fields carries nothing to deliver and is dropped.
	 *
	 * @throws InvitationSendingException
	 */
	public function send(array $invitation): void
	{
		if (
			!isset(
				$invitation['eventId'],
				$invitation['addresserId'],
				$invitation['receiverId'],
				$invitation['type'],
			)
		)
		{
			return;
		}

		$changeFields = $invitation['changeFields'] ?? [];

		$this->sendInvitation(
			(int)$invitation['eventId'],
			(int)$invitation['addresserId'],
			(int)$invitation['receiverId'],
			(string)$invitation['type'],
			is_array($changeFields) ? array_column($changeFields, 'fieldKey') : [],
			$this->readSpentAttempts($invitation),
		);
	}

	/**
	 * The legacy counter holds the attempts already spent: the old handler added one to it to get
	 * the number of the attempt it was about to make, exactly as this queue does with
	 * deliveryAttempt. Carried over as it is, an invitation the legacy handler has already tried
	 * keeps its three sends in total instead of starting them over. A missing or unusable value
	 * means nothing was spent yet.
	 */
	private function readSpentAttempts(array $invitation): int
	{
		$counter = $invitation['counterInvitation'] ?? 0;

		return is_numeric($counter) ? max(0, (int)$counter) : 0;
	}

	/**
	 * @param string[] $changedFieldKeys
	 * @param int $deliveryAttempt Attempts already spent on this invitation elsewhere.
	 *
	 * @throws InvitationSendingException
	 */
	public function sendInvitation(
		int $eventId,
		int $addresserId,
		int $receiverId,
		string $type,
		array $changedFieldKeys = [],
		int $deliveryAttempt = 0,
	): void
	{
		$message = new EmailInvitationMessage(
			eventId: $eventId,
			addresserId: $addresserId,
			receiverId: $receiverId,
			type: $type,
			changedFieldKeys: $changedFieldKeys,
			deliveryAttempt: $deliveryAttempt,
		);

		try
		{
			$message->send(
				Queue::EmailInvitation->value,
				[new ItemIdParam($eventId)],
			);
		}
		catch (Throwable $exception)
		{
			// The bus declares more than the refusal of the broker: a broken queue configuration, a
			// module that cannot be loaded and any system failure reach this point too, and resolving
			// the bus out of the container has failures of its own. Every one of them means the
			// invitation is not queued, so every one owes the caller the declared failure and the
			// journal a trace. Nothing is masked: the failure is carried on, in the expected shape.
			$errorMessage = sprintf(
				'Unable to send email invitation message: "%s"',
				$exception->getMessage(),
			);
			$this->logErrorSafely(
				$errorMessage,
				[
					'type' => self::LOG_TYPE,
					'entityId' => $eventId,
					'exception' => LogContext::fromThrowable($exception),
				],
			);

			throw new InvitationSendingException(
				$errorMessage,
				(int)$exception->getCode(),
				$exception,
			);
		}
	}

	/**
	 * The journal lives in the database as well, so a failing write must never take the place of
	 * the typed failure the caller is waiting for.
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
}
