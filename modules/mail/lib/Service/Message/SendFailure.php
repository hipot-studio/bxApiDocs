<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Message;

use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageErrorText;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Result;

/**
 * Refusals of a send that the author has to act on, shared by every path a letter leaves through: the form
 * and the queue of mail, and the sending of crm. The codes are literals and not the constants of the kernel,
 * so that they keep working with a kernel that does not declare them.
 */
final class SendFailure
{
	public const CODE_SENDER_ADDRESS_MISMATCH = 'MAIL_SENDER_ADDRESS_MISMATCH';
	public const CODE_SENDER_UNAVAILABLE = 'MAIL_SENDER_UNAVAILABLE';
	public const CODE_SEND_FAILED = 'MAIL_SEND_FAILED';

	private const CONTROLLED_CODES = [
		self::CODE_SENDER_ADDRESS_MISMATCH,
		self::CODE_SENDER_UNAVAILABLE,
	];

	private const MESSAGE_OPTION = 'sendError';

	/**
	 * The refusal the author can fix by choosing another sender, or null for any other outcome. A mismatch of
	 * the address is worded by mail, so that crm names it the way the form of mail does.
	 */
	public static function getControlledError(?Result $result): ?Error
	{
		if ($result === null || $result->isSuccess())
		{
			return null;
		}

		foreach ($result->getErrors() as $error)
		{
			if (!self::isControlled($error))
			{
				continue;
			}

			return (string)$error->getCode() === self::CODE_SENDER_ADDRESS_MISMATCH
				? self::createSenderAddressMismatchError()
				: $error;
		}

		return null;
	}

	public static function isControlled(Error $error): bool
	{
		return in_array((string)$error->getCode(), self::CONTROLLED_CODES, true);
	}

	/** Worded by the kernel, the way the kernel itself refuses such a send. */
	public static function createSenderUnavailableError(): Error
	{
		Loc::loadMessages((new \ReflectionClass(Sender::class))->getFileName());

		return new Error(
			(string)Loc::getMessage('MAIN_MAIL_SENDER_UNAVAILABLE_ERROR'),
			self::CODE_SENDER_UNAVAILABLE,
		);
	}

	public static function createSenderAddressMismatchError(): Error
	{
		return new Error(self::getText(self::CODE_SENDER_ADDRESS_MISMATCH), self::CODE_SENDER_ADDRESS_MISMATCH);
	}

	public static function getText(string $code, ?string $languageId = null): string
	{
		return ScheduledMessageErrorText::getReason($code, $languageId);
	}

	/**
	 * Keeps the refusal with the letter. The code is what is read back: the text is built for its reader, while
	 * the process that failed to send works in a language of its own.
	 */
	public static function markMessageFailed(int $messageId, array $messageOptions, Error $error): void
	{
		$messageOptions[self::MESSAGE_OPTION] = [
			'code' => (string)$error->getCode(),
			'message' => $error->getMessage(),
		];

		MailMessageTable::update($messageId, ['OPTIONS' => $messageOptions]);
	}

	/**
	 * The refusal kept with the letter, worded in the language of the reader. A code the texts do not know
	 * keeps the message saved with it, which is all that letters failed before the codes were mapped have.
	 */
	public static function getMessageFailure(array $message, ?string $languageId = null): ?Error
	{
		$options = is_array($message['OPTIONS'] ?? null) ? $message['OPTIONS'] : [];
		$failure = is_array($options[self::MESSAGE_OPTION] ?? null) ? $options[self::MESSAGE_OPTION] : [];
		$code = trim((string)($failure['code'] ?? ''));

		if ($code !== '' && ScheduledMessageErrorText::hasReason($code))
		{
			return new Error(self::getText($code, $languageId), $code);
		}

		$savedMessage = trim((string)($failure['message'] ?? ''));
		if ($savedMessage === '')
		{
			return null;
		}

		return new Error($savedMessage, $code);
	}
}
