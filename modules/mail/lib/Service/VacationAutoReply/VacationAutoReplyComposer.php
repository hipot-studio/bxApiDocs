<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\VacationAutoReply;

use Bitrix\Mail\Helper\Message\MessageSender;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Service\SharedSignature\SignatureResolver;
use Bitrix\Mail\Service\Signature\SignatureTemplateResolver;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Text\Emoji;

Loc::loadMessages(__FILE__);

/**
 * The answer itself: who it goes from, who it goes to, what it is called and what it says.
 *
 * The sender is always the address of the mailbox the letter arrived in, and the only recipient is the
 * sender of that letter: "To" and "Copy" of the original never take part.
 * The body is the text the user saved plus the mailbox signature as it stands when the answer is built;
 * a missing or unresolved signature is no reason to keep silent.
 *
 * The composer neither sends the letter nor writes to the log of notified senders. The quote of the
 * original letter, `In-Reply-To` and the copy in "Sent" are the business of the existing send path
 * (`Helper\Message\MessageSender::reply()`), which is why the command carries the identifier of the
 * letter being answered and nothing more about the thread.
 */
class VacationAutoReplyComposer
{
	private const SUBJECT_PREFIX = 'Re';

	private const BODY_SIGNATURE_SEPARATOR = '<br><br>';

	/**
	 * Senders available to a user as this process has already collected them, keyed by user. Collecting
	 * the list asks after every mailbox available to the user, and a pass of the queue builds one answer
	 * after another for the same user - the vacation of one person is answered letter by letter.
	 *
	 * The memory is that of the class and not of the instance: a responder, and with it a composer, is
	 * built anew for every job of the pass.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private static array $sendersByUser = [];

	private SignatureResolver $signatureResolver;

	private SignatureTemplateResolver $templateResolver;

	public function __construct(
		?SignatureResolver $signatureResolver = null,
		?SignatureTemplateResolver $templateResolver = null,
	)
	{
		$this->signatureResolver = $signatureResolver ?? new SignatureResolver();
		$this->templateResolver = $templateResolver ?? new SignatureTemplateResolver();
	}

	/**
	 * @param array $message Message as the `mail`/`onMailMessageNew` event publishes it.
	 * @param array $setting Setting of the mailbox (VacationAutoReplyService::getSetting()).
	 * @param string $recipient Address the rules decided to answer.
	 * @return array{
	 *     messageId: int,
	 *     from: string,
	 *     recipients: string[],
	 *     subject: string,
	 *     body: string,
	 *     userId: int,
	 *     mailboxId: int,
	 * } Parameters of the answer in the shape the send path takes them. The mailbox is the one the
	 *   sender was picked by: two mailboxes of a user may carry the same address, and the transport and
	 *   the copy in "Sent" belong to a mailbox and not to an address.
	 */
	public function build(array $message, array $setting, string $recipient): array
	{
		$mailboxId = (int)($message['MAILBOX_ID'] ?? $setting['mailboxId'] ?? 0);
		$userId = (int)($setting['userId'] ?? 0);
		$sender = $this->resolveSenderIdentity($mailboxId, $userId);

		return [
			'messageId' => (int)($message['ID'] ?? 0),
			'from' => $sender['email'],
			'recipients' => [$recipient],
			'subject' => self::buildSubject($message),
			'body' => $this->buildBody($setting, $mailboxId, $userId, $sender),
			'userId' => $userId,
			'mailboxId' => $mailboxId,
		];
	}

	/**
	 * Address and display name of the mailbox as the compose form knows them: the remembered signature
	 * choice of the user is keyed by this very pair, so a name of our own would lose his choice.
	 *
	 * A mailbox with no sender of its own still gets an address - the send path refuses such a letter
	 * itself, and that refusal belongs to it and not here.
	 *
	 * @return array{email: string, name: string}
	 */
	protected function resolveSenderIdentity(int $mailboxId, int $userId): array
	{
		if ($mailboxId <= 0)
		{
			return ['email' => '', 'name' => ''];
		}

		foreach ($this->readUserSenders($userId) as $sender)
		{
			if ((int)($sender['mailboxId'] ?? 0) === $mailboxId)
			{
				return [
					'email' => (string)($sender['email'] ?? ''),
					'name' => (string)($sender['name'] ?? ''),
				];
			}
		}

		$mailbox = MailboxTable::getRow([
			'select' => ['EMAIL'],
			'filter' => ['=ID' => $mailboxId],
		]);

		return ['email' => (string)($mailbox['EMAIL'] ?? ''), 'name' => ''];
	}

	/**
	 * The list of the user, collected once per user in this process. A list collected for nobody belongs
	 * to whoever the process runs as and is not remembered under a user at all.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readUserSenders(int $userId): array
	{
		if ($userId <= 0)
		{
			return $this->loadUserSenders($userId);
		}

		if (!array_key_exists($userId, self::$sendersByUser))
		{
			self::$sendersByUser[$userId] = $this->loadUserSenders($userId);
		}

		return self::$sendersByUser[$userId];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function loadUserSenders(int $userId): array
	{
		return Sender::prepareUserMailboxes($userId);
	}

	/**
	 * Signature of the mailbox at the moment of sending, with its macros already materialised by the
	 * existing provider. The text of an auto-reply carries no macros of its own.
	 *
	 * A signature model that is out of order costs the answer its signature and nothing more: the same
	 * graceful fallback the compose form does.
	 *
	 * @param array{email: string, name: string} $sender
	 */
	protected function resolveSignature(int $mailboxId, int $userId, array $sender): string
	{
		if ($userId <= 0)
		{
			return '';
		}

		try
		{
			$template = $this->signatureResolver->resolveDefault(
				$mailboxId,
				$userId,
				$sender['email'],
				$sender['name'],
			);
			if (trim($template) === '')
			{
				return '';
			}

			return $this->templateResolver->resolve(
				$template,
				$userId,
				$sender['email'],
				$sender['name'],
				$mailboxId,
			);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return '';
		}
	}

	/**
	 * @param array{email: string, name: string} $sender
	 */
	private function buildBody(array $setting, int $mailboxId, int $userId, array $sender): string
	{
		$body = (string)($setting['body'] ?? '');
		$signature = $this->resolveSignature($mailboxId, $userId, $sender);

		return trim($signature) === '' ? $body : $body . self::BODY_SIGNATURE_SEPARATOR . $signature;
	}

	/**
	 * `Re: <subject>` without doubling the prefix the letter already carries; a letter with no subject
	 * of its own is answered under one of ours.
	 *
	 * The subject arrives in either of two forms: read through the ORM it is already decoded, while the
	 * data of the event still carries emoji encoded. Decoding a decoded subject changes nothing, so one
	 * decode here covers both.
	 *
	 * Whichever form it arrives in, the subject is the text of whoever sent the letter: the control
	 * characters that would end a header line and start one of the sender's own are dropped here as well,
	 * so the answer does not depend on the send path alone for that.
	 */
	private static function buildSubject(array $message): string
	{
		$decoded = MessageSender::stripControlCharacters(Emoji::decode((string)($message['SUBJECT'] ?? '')));
		$subject = trim($decoded);
		if ($subject === '')
		{
			$subject = (string)Loc::getMessage('MAIL_VACATION_AUTO_REPLY_NO_SUBJECT');
		}

		return trim((string)preg_replace(
			sprintf('/^(%s:\s*)?/i', preg_quote(self::SUBJECT_PREFIX, '/')),
			self::SUBJECT_PREFIX . ': ',
			$subject,
		));
	}
}
