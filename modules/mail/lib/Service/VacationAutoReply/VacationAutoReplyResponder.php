<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\VacationAutoReply;

use Bitrix\Mail\Helper\Message\MessageSender;
use Bitrix\Mail\Internals\VacationAutoReplyLogTable;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Type\DateTime;

/**
 * One letter through the whole answer: ask the rules, build the answer, reserve the sender and send only
 * from the process that owns the reservation.
 *
 * ALG-02 (NORMATIVE):
 *   respond(message, setting):
 *     verdict = rules.decide(message, setting, now)
 *     if verdict is skip: return skipped(verdict.reason)
 *     command = composer.build(message, setting)   # sender = mailbox email, to = verdict.sender
 *     if not log.insert_ignore(setting.mailbox_id, setting.period_id, normalize(verdict.sender), now):
 *                                        return skipped(already_notified)
 *     result = send_message_service.send(command)
 *     if not result.is_success: return failed(result.error)
 *     return sent()
 *
 * The unique log row is a persistent reservation, not a delivery receipt. It is deliberately not released
 * after a send failure: SMTP may accept the letter and still fail before confirming it, so another attempt
 * could send a duplicate. This gives the feature at-most-once attempt semantics; a process failure after the
 * reservation and before SMTP can cost the answer, but can never produce a second one.
 *
 * Limits, retries, the copy in "Sent" and the tie to the conversation all stay with the existing send
 * path, and no CRM logic of our own joins them. A failure has no retry rules or separate channel: it is
 * written to the module log and that is all.
 */
class VacationAutoReplyResponder
{
	public const STATUS_SENT = 'sent';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_FAILED = 'failed';

	/** RFC 3834 mark of an automatic answer: the other side must not answer this letter of ours back. */
	private const AUTO_REPLY_HEADERS = ['Auto-Submitted' => 'auto-replied'];

	private VacationAutoReplyRules $rules;

	private VacationAutoReplyComposer $composer;

	public function __construct(
		?VacationAutoReplyRules $rules = null,
		?VacationAutoReplyComposer $composer = null,
	)
	{
		$this->rules = $rules ?? new VacationAutoReplyRules();
		$this->composer = $composer ?? new VacationAutoReplyComposer();
	}

	/**
	 * @param array $message Message as the `mail`/`onMailMessageNew` event publishes it.
	 * @param array $setting Setting of the mailbox (VacationAutoReplyService::getSetting()).
	 * @param bool $isFreshArrival Parameter of the same event, never recomputed here.
	 * @return array{status: string, reason: string|null, error: string|null}
	 */
	public function respond(
		array $message,
		array $setting,
		bool $isFreshArrival,
		?DateTime $now = null,
	): array
	{
		$now ??= new DateTime();

		$verdict = $this->rules->decide($message, $setting, $isFreshArrival, $now);
		if (!VacationAutoReplyRules::isReply($verdict))
		{
			return self::skipped((string)$verdict['reason']);
		}

		$recipient = (string)$verdict['sender'];
		$command = $this->composer->build($message, $setting, $recipient);
		$isReservationOwner = VacationAutoReplyLogTable::insertIgnore(
			(int)($setting['mailboxId'] ?? 0),
			(int)($setting['periodId'] ?? 0),
			$recipient,
			$now,
		);
		if (!$isReservationOwner)
		{
			return self::skipped(VacationAutoReplyRules::REASON_ALREADY_NOTIFIED);
		}

		try
		{
			$this->send($command);
		}
		catch (\Throwable $exception)
		{
			self::logFailedSend($command, $exception);

			return self::failed($exception->getMessage());
		}

		return self::sent();
	}

	/**
	 * The send path of the module, told to mark the letter as an automatic answer. The user of the
	 * mailbox is passed explicitly: the answer is sent by a sync pass and has no web context of its own.
	 *
	 * The mailbox goes with the address it was picked by, so that the transport and the copy in "Sent"
	 * are those of the mailbox the letter arrived in: a user with two mailboxes of the same address
	 * would otherwise have the answer sent through whichever of them the address is found in first. A
	 * mailbox the composer could not read is left to the address alone - the send path refuses an
	 * identifier that is not a mailbox at all.
	 *
	 * @param array{
	 *     messageId: int,
	 *     from: string,
	 *     recipients: string[],
	 *     subject: string,
	 *     body: string,
	 *     userId: int,
	 *     mailboxId: int,
	 * } $command
	 */
	protected function send(array $command): void
	{
		$mailboxId = (int)$command['mailboxId'];

		(new MessageSender())->reply(
			messageId: $command['messageId'],
			from: $command['from'],
			recipients: $command['recipients'],
			subject: $command['subject'],
			body: $command['body'],
			userId: $command['userId'],
			mailboxId: $mailboxId > 0 ? $mailboxId : null,
			extraHeaders: self::AUTO_REPLY_HEADERS,
		);
	}

	/**
	 * @param array<string, mixed> $command
	 */
	private static function logFailedSend(array $command, \Throwable $exception): void
	{
		(new LoggerFactory())->createById('mail.VacationAutoReply')?->error(
			'Vacation auto-reply was not sent.',
			[
				'messageId' => (int)($command['messageId'] ?? 0),
				'userId' => (int)($command['userId'] ?? 0),
				'error' => $exception->getMessage(),
			],
		);
	}

	/**
	 * @return array{status: string, reason: string, error: null}
	 */
	private static function skipped(string $reason): array
	{
		return ['status' => self::STATUS_SKIPPED, 'reason' => $reason, 'error' => null];
	}

	/**
	 * @return array{status: string, reason: null, error: string}
	 */
	private static function failed(string $error): array
	{
		return ['status' => self::STATUS_FAILED, 'reason' => null, 'error' => $error];
	}

	/**
	 * @return array{status: string, reason: null, error: null}
	 */
	private static function sent(): array
	{
		return ['status' => self::STATUS_SENT, 'reason' => null, 'error' => null];
	}
}
