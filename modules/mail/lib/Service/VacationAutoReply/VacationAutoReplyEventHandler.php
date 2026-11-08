<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\VacationAutoReply;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Async\Message\VacationAutoReplyMessage;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;

/**
 * Seam between saving an incoming letter and the auto-reply.
 *
 * Nothing here may escape: the event is sent from inside `CMailMessage::saveMessage()`, and
 * `Event::send()` rethrows whatever a handler throws, so a refused auto-reply would cost the portal the
 * letter itself.
 *
 * The seam only decides whether the letter is worth a job of the queue; the answer itself is built and
 * sent by `Internal\Async\Receiver\VacationAutoReplyReceiver`. The checks are ordered by their price: the
 * switch of the feature costs nothing, the arrival flag and the folders of the letter are parameters of
 * the event, and only then does a letter pay for the setting of the mailbox - one row by its primary key,
 * read once per mailbox while the auto-reply answers and once per fresh letter while it does not.
 */
class VacationAutoReplyEventHandler
{
	private const QUEUE_ID = 'mail_vacation_auto_reply';

	/**
	 * Folder flags the rules turn a letter down by (REASON_NOT_INCOMING), as the event publishes them.
	 *
	 * The event names the folder the letter came in through, and the rules ask every folder it lies in and
	 * take them together, so a flag raised here is a flag the rules would raise too. A letter that moves
	 * afterwards is still judged by them: the seam only keeps out what is already turned down.
	 */
	private const NOT_INCOMING_FLAGS = ['IS_OUTCOME', 'IS_DRAFT', 'IS_TRASH', 'IS_SPAM'];

	/**
	 * Settings this process has already read and found running, keyed by mailbox: a pass of the sync
	 * brings in the letters of a mailbox one after another, and while the auto-reply answers them they
	 * are all judged by the same row.
	 *
	 * Kept without an expiry, because going stale in this direction is safe: a period switched off, cut
	 * short or moved while a pass is running only puts jobs into the queue that the queue then refuses,
	 * as the job reads the switch of the feature, the setting and the rules once more before an answer
	 * leaves.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $settingsByMailbox = [];

	public static function onMailMessageNew(Event $event): void
	{
		try
		{
			static::handleMailMessageNew($event);
		}
		catch (\Throwable $exception)
		{
			$message = $event->getParameter('message');
			static::logFailure(is_array($message) ? $message : [], $exception);
		}
	}

	protected static function handleMailMessageNew(Event $event): void
	{
		if (!Feature::isVacationAutoReplyAvailable())
		{
			return;
		}

		$message = $event->getParameter('message');
		if (!is_array($message))
		{
			return;
		}

		$mailboxId = (int)($message['MAILBOX_ID'] ?? 0);
		$messageId = (int)($message['ID'] ?? 0);
		if ($mailboxId <= 0 || $messageId <= 0)
		{
			return;
		}

		// A letter brought in by a history pass is never answered, and turning it down here keeps
		// a whole load of old mail out of the queue: the flag is a parameter of the event and cannot be
		// derived from the letter afterwards, so the queue holds fresh arrivals only.
		if (!$event->getParameter('isFreshArrival'))
		{
			return;
		}

		if (self::isNotIncoming($message))
		{
			return;
		}

		$service = static::getSettingService();
		$setting = self::readSetting($mailboxId, $service);
		if ($setting === null || !$service->isPeriodRunning($setting))
		{
			return;
		}

		static::enqueue($mailboxId, $messageId);
	}

	/**
	 * Setting of the mailbox, taken from the storage unless this process already knows the answer.
	 *
	 * Only a running setting is remembered. A negative answer may become positive in another process
	 * while this sync pass is still bringing letters in, and there is no reliable in-process invalidation
	 * for that change: taking the negative answer from memory would lose those letters for good.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function readSetting(int $mailboxId, VacationAutoReplyService $service): ?array
	{
		if (isset(self::$settingsByMailbox[$mailboxId]))
		{
			return self::$settingsByMailbox[$mailboxId];
		}

		$setting = $service->getSetting($mailboxId);
		if ($setting !== null && $service->isPeriodRunning($setting))
		{
			self::$settingsByMailbox[$mailboxId] = $setting;

			return $setting;
		}

		return $setting;
	}

	/**
	 * @param array $message Message as the event publishes it.
	 */
	private static function isNotIncoming(array $message): bool
	{
		foreach (self::NOT_INCOMING_FLAGS as $flag)
		{
			if (!empty($message[$flag]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The letter goes to the queue of the module, and the answer to it leaves in a pass of that queue.
	 *
	 * Sending from here is not possible at all: the letter is tied to its uid row only after this event
	 * returns (`Helper\Mailbox\Imap::syncMessage()` links them), while the send path looks the letter up
	 * through that very tie (`MailMessageTable::getConsistentById()`). The queue also outlives the hit: a
	 * job survives a pass that dies halfway and is retried, which the end of a hit is not.
	 *
	 * Only the identifiers are handed over. Whether the answer is still appropriate is decided again when
	 * the job is processed - by then the period may be over, the letter may be in the trash and the sender
	 * may already have been answered.
	 */
	protected static function enqueue(int $mailboxId, int $messageId): void
	{
		(new VacationAutoReplyMessage($mailboxId, $messageId))->send(self::QUEUE_ID);
	}

	protected static function getSettingService(): VacationAutoReplyService
	{
		return new VacationAutoReplyService();
	}

	/**
	 * A failure of the log must not break saving the letter either, so this one swallows its own.
	 *
	 * @param array $message Message as the event publishes it, or an empty array when there was none.
	 */
	private static function logFailure(array $message, \Throwable $exception): void
	{
		try
		{
			(new LoggerFactory())->createById('mail.VacationAutoReply')?->error(
				'Vacation auto-reply was not processed for an incoming letter.',
				[
					'mailboxId' => (int)($message['MAILBOX_ID'] ?? 0),
					'messageId' => (int)($message['ID'] ?? 0),
					'error' => $exception->getMessage(),
				],
			);
		}
		catch (\Throwable)
		{
		}
	}
}
