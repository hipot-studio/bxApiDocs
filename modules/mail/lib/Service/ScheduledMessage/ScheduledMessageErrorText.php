<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Mail\Service\Message\SendFailure;
use Bitrix\Mail\Service\Message\SendMessageService;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * The only place a failed send is put into words. The messages the services keep in `LAST_ERROR` are English
 * diagnostics meant for a log, so every reader of a refusal (the notification of its author, the row of the
 * section and the screen of a letter the queue failed to send) asks here and names the same failure the same
 * way. A code the map does not know yet answers with a phrase that still leaves the reader a next step.
 */
final class ScheduledMessageErrorText
{
	private const REASON_PHRASE = [
		ScheduledMessageService::ERROR_MAILBOX_ACCESS_DENIED
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_MAILBOX_ACCESS_DENIED',
		ScheduledMessageDispatcher::ERROR_SENDER_UNAVAILABLE
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_SENDER_UNAVAILABLE',
		ScheduledMessageDispatcher::ERROR_ATTACHMENT_UNAVAILABLE
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_ATTACHMENT_UNAVAILABLE',
		ScheduledMessageDispatcher::ERROR_SEND_LIMIT
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_SEND_LIMIT',
		ScheduledMessageDispatcher::ERROR_SEND_EXCEPTION
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_SEND_EXCEPTION',
		ScheduledMessageDispatcher::ERROR_EXPIRED
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_EXPIRED',
		SendMessageService::ERROR_MAILBOX_UNAVAILABLE
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_MAILBOX_UNAVAILABLE',
		SendMessageService::ERROR_INVALID_HEADER
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_INVALID_HEADER',
		SendMessageService::ERROR_INVALID_AUTHOR
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_INVALID_AUTHOR',
		SendMessageService::ERROR_EMPTY_RECIPIENTS
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_RECIPIENT_REQUIRED',
		SendMessageService::ERROR_TOO_MANY_RECIPIENTS
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_RECIPIENT_LIMIT',
		SendMessageService::ERROR_SEND_FAILED
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_SEND_FAILED',
		ScheduledMessageAttachmentService::ERROR_ATTACHMENT_SIZE_LIMIT
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_ATTACHMENT_SIZE_LIMIT',
		SendFailure::CODE_SENDER_ADDRESS_MISMATCH
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_SENDER_ADDRESS_MISMATCH',
		SendFailure::CODE_SENDER_UNAVAILABLE
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_SENDER_UNAVAILABLE',
		SendFailure::CODE_SEND_FAILED
			=> 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_SEND_FAILED',
	];

	private const FALLBACK_PHRASE = 'MAIL_SCHEDULED_MESSAGE_ERROR_REASON_UNKNOWN';

	public static function hasReason(string $code): bool
	{
		return isset(self::REASON_PHRASE[$code]);
	}

	public static function getReason(string $code, ?string $languageId = null): string
	{
		return (string)Loc::getMessage(self::REASON_PHRASE[$code] ?? self::FALLBACK_PHRASE, null, $languageId);
	}

	/**
	 * The reason kept in `LAST_ERROR`, or null when the field holds none. The column is nullable and its own
	 * declaration reads whatever cannot be parsed as "no reason recorded", so a row without a reason is a
	 * normal one: it is left without a reason rather than given the fallback of an unknown code.
	 */
	public static function getReasonFromLastError(mixed $lastError, ?string $languageId = null): ?string
	{
		if (!is_array($lastError))
		{
			return null;
		}

		$code = trim((string)($lastError['code'] ?? ''));

		return $code === '' ? null : self::getReason($code, $languageId);
	}
}
