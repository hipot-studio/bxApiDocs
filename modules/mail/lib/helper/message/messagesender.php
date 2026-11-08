<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Internal\Service\Signature\Image\SignatureImageSendPreparation;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationActionGuard;
use Bitrix\Mail\Message as MailMessage;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Context;
use Bitrix\Main\Mail\Mail;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\Mail\SenderSendCounter;
use Bitrix\Main\SystemException;

final class MessageSender
{
	private const SANITIZER_SOURCE_PLACEHOLDER_PREFIX = 'https://mail.local/';

	private const INLINE_ATTACHMENT_SOURCE_PATTERN = '/(?<![A-Za-z0-9_-])(src)(\s*+=\s*+)(?:"\s*+aid:(\d+)\s*+"|\'\s*+aid:(\d+)\s*+\'|aid:(\d+)(?=[\s\/>]))/i';
	private const CID_SOURCE_PATTERN = '/(?<![A-Za-z0-9_-])(src)(\s*+=\s*+)(?:"\s*+(cid:[^"<>[:cntrl:]]++)\s*+"|\'\s*+(cid:[^\'<>[:cntrl:]]++)\s*+\'|(cid:(?:[^\s<>\/[:cntrl:]]|\/(?!>))++)(?=[\s>]|\/>))/i';
	private MessageSenderSourceProvider $sourceProvider;
	private ?\Closure $transport;
	private ?\Closure $quoteBuilder;
	private ?\Closure $bodySanitizer;
	private ?\Closure $hostnameResolver;

	public function __construct(
		?MessageSenderSourceProvider $sourceProvider = null,
		?\Closure $transport = null,
		?\Closure $quoteBuilder = null,
		?\Closure $bodySanitizer = null,
		?\Closure $hostnameResolver = null,
	)
	{
		$this->sourceProvider = $sourceProvider ?? new DefaultMessageSenderSourceProvider();
		$this->transport = $transport;
		$this->quoteBuilder = $quoteBuilder;
		$this->bodySanitizer = $bodySanitizer;
		$this->hostnameResolver = $hostnameResolver;
	}

	/**
	 * Header names an extra header of a caller may carry, besides the `X-` space of its own: everything
	 * else belongs either to the send path or to the core, and a caller that took one of those over would
	 * leave the letter with an envelope that no longer matches what was actually sent.
	 *
	 * The list is an allowlist on purpose: the core writes MIME and transport headers of its own
	 * (`Content-Type`, `Content-Transfer-Encoding`, `MIME-Version`, `Date`, `Sender`, `Return-Path`), and
	 * a denylist would have to be extended every time it starts writing one more.
	 */
	private const ALLOWED_HEADER_NAMES = [
		'auto-submitted',
	];

	/** The `X-` space belongs to the caller: no header of that space carries an envelope of the letter. */
	private const ALLOWED_HEADER_NAME_PREFIX = 'x-';

	/** Names of the `X-` space the core writes itself (`Main\Mail\Mail::getHeaders()`). */
	private const CORE_X_HEADER_NAMES = [
		'x-priority',
		'x-mid',
	];

	/** Field name of a header, as RFC 5322 spells it: printable ASCII without the colon. */
	private const HEADER_NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/';

	/**
	 * Characters no value written into a header may carry: a line break inside one ends the header and
	 * turns everything after it into headers - and past an empty line, into the body - of the letter the
	 * portal actually sends.
	 *
	 * The values the send path writes are not all its own: the subject and the identifier of an answer
	 * come from the letter being answered, that is from whoever sent it to the portal.
	 *
	 * The horizontal tab (`\x09`) is left out of the set: RFC 5322 counts it as whitespace of the value
	 * like the space next to it, it ends no line and starts no header - a continuation line takes a line
	 * break first, and those stay refused - so a subject or an address written with one is a value of a
	 * person and not a smuggled header.
	 */
	private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]/';

	/**
	 * @param string[] $recipients
	 * @param string[] $cc
	 * @param string[] $bcc
	 * @return array{success: bool, to: string[]}
	 * @throws SystemException
	 */
	public function send(
		string $from,
		array $recipients,
		string $subject,
		string $body,
		int $userId,
		array $cc = [],
		array $bcc = [],
		?int $senderId = null,
		?int $mailboxId = null,
	): array
	{
		return $this->doSend(
			from: $from,
			recipients: $recipients,
			subject: $subject,
			body: $body,
			userId: $userId,
			cc: $cc,
			bcc: $bcc,
			senderId: $senderId,
			mailboxId: $mailboxId,
		);
	}

	/**
	 * @param string[] $recipients
	 * @param string[] $cc
	 * @param string[] $bcc
	 * @return array{success: bool, to: string[]}
	 * @throws SystemException
	 */
	public function forward(
		int $messageId,
		string $from,
		array $recipients,
		string $subject,
		string $body,
		int $userId,
		array $cc = [],
		array $bcc = [],
		?int $senderId = null,
		?int $mailboxId = null,
	): array
	{
		$originalMessage = $this->getOriginalMessage($messageId, $userId);
		$this->assertMailboxSendingAllowedById((int)($originalMessage['MAILBOX_ID'] ?? 0));
		$quote = $this->buildQuote($originalMessage);
		$expectedAttachments = max(
			(int)($originalMessage['OPTIONS']['attachments'] ?? 0),
			(int)($originalMessage['ATTACHMENTS'] ?? 0),
		);
		$attachments = [];
		if ($expectedAttachments > 0)
		{
			[$attachments, $quote] = $this->getMessageAttachments(
				$messageId,
				$quote,
				false,
				$expectedAttachments,
			);
		}
		$body = $this->combineUserBodyWithQuote($body, $quote);

		return $this->doSend(
			from: $from,
			recipients: $recipients,
			subject: $subject,
			body: $body,
			userId: $userId,
			cc: $cc,
			bcc: $bcc,
			attachments: $attachments,
			senderId: $senderId,
			mailboxId: $mailboxId,
		);
	}

	/**
	 * @param string[] $recipients
	 * @param string[] $cc
	 * @param string[] $bcc
	 * @param array<string, string> $extraHeaders Headers of the caller added after the assembled ones.
	 * @return array{success: bool, to: string[]}
	 * @throws SystemException
	 */
	public function reply(
		int $messageId,
		string $from,
		array $recipients,
		string $subject,
		string $body,
		int $userId,
		array $cc = [],
		array $bcc = [],
		?int $senderId = null,
		?int $mailboxId = null,
		array $extraHeaders = [],
	): array
	{
		$originalMessage = $this->getOriginalMessage($messageId, $userId);
		$this->assertMailboxSendingAllowedById((int)($originalMessage['MAILBOX_ID'] ?? 0));
		$quote = $this->buildQuote($originalMessage);
		$expectedAttachments = max(
			(int)($originalMessage['OPTIONS']['attachments'] ?? 0),
			(int)($originalMessage['ATTACHMENTS'] ?? 0),
		);
		$attachments = [];
		if ($expectedAttachments > 0)
		{
			[$attachments, $quote] = $this->getMessageAttachments(
				$messageId,
				$quote,
				true,
				$expectedAttachments,
			);
		}
		$body = $this->combineUserBodyWithQuote($body, $quote);
		$inReplyTo = !empty($originalMessage['MSG_ID'])
			? sprintf('<%s>', $originalMessage['MSG_ID'])
			: null
		;

		return $this->doSend(
			from: $from,
			recipients: $recipients,
			subject: $subject,
			body: $body,
			userId: $userId,
			cc: $cc,
			bcc: $bcc,
			attachments: $attachments,
			inReplyTo: $inReplyTo,
			senderId: $senderId,
			mailboxId: $mailboxId,
			extraHeaders: $extraHeaders,
		);
	}

	/**
	 * @throws SystemException
	 */
	public function resolveSender(string $from, int $userId): string
	{
		return $this->formatSender($this->findAvailableSender($from, $userId), $userId);
	}

	/**
	 * Sender list item as the provider returns it: besides the address it carries the identifiers
	 * the kernel needs to scope the transport to a single owner.
	 *
	 * @return array<string, mixed>
	 * @throws SystemException
	 */
	private function findAvailableSender(string $from, int $userId): array
	{
		$email = self::extractEmail($from);

		$senders = UserSenderDataProvider::getUserAvailableSenders(
			userId: $userId,
		);

		foreach ($senders as $sender)
		{
			if (mb_strtolower($sender['email'] ?? '') === $email)
			{
				return $sender;
			}
		}

		throw new SystemException('Sender email is not available for this user.');
	}

	/**
	 * @return array{sender: array<string, mixed>, identity: ?Sender\Identity}
	 */
	private function resolveSenderSelection(
		string $from,
		int $userId,
		?int $senderId,
		?int $mailboxId,
	): array
	{
		if (($senderId !== null && $senderId <= 0) || ($mailboxId !== null && $mailboxId <= 0))
		{
			throw new SystemException('Sender identifiers must be positive integers.');
		}

		if ($mailboxId === null && $senderId === null)
		{
			return [
				'sender' => $this->findAvailableSender($from, $userId),
				'identity' => null,
			];
		}
		// Identity and the identities API reached main by different commits a week apart,
		// so a main carrying the class but not the method is a real state, not a theory.
		if (
			!class_exists(Sender\Identity::class)
			|| !method_exists(UserSenderDataProvider::class, 'getUserAvailableSenderIdentities')
		)
		{
			return [
				'sender' => $this->findAvailableSender($from, $userId),
				'identity' => null,
			];
		}

		$email = mb_strtolower(self::extractEmail($from));
		$senders = UserSenderDataProvider::getUserAvailableSenderIdentities($userId);
		foreach ($senders as $sender)
		{
			if (mb_strtolower((string)($sender['email'] ?? '')) !== $email)
			{
				continue;
			}

			$availableMailboxId = (int)($sender['mailboxId'] ?? 0);
			if (($mailboxId ?? 0) > 0)
			{
				if ($availableMailboxId === $mailboxId)
				{
					return [
						'sender' => $sender,
						'identity' => $this->resolveSenderIdentity($sender, $senderId, $mailboxId),
					];
				}

				continue;
			}

			if ((int)($sender['id'] ?? 0) === $senderId)
			{
				return [
					'sender' => $sender,
					'identity' => $this->resolveSenderIdentity($sender, $senderId, null),
				];
			}
		}

		throw new SystemException('Sender is not available for this user.');
	}

	/**
	 * @param array<string, mixed> $sender
	 */
	private function resolveSenderIdentity(
		array $sender,
		?int $senderId = null,
		?int $mailboxId = null,
	): ?Sender\Identity
	{
		$mailboxId = ($mailboxId ?? 0) > 0 ? $mailboxId : (int)($sender['mailboxId'] ?? 0);
		if ($mailboxId > 0)
		{
			return Sender\Identity::fromMailbox($mailboxId);
		}

		$senderId = ($senderId ?? 0) > 0 ? $senderId : (int)($sender['id'] ?? 0);

		return $senderId > 0 ? Sender\Identity::fromSender($senderId) : null;
	}

	/**
	 * @param array<string, mixed> $sender
	 */
	private function formatSender(array $sender, int $userId): string
	{
		return UserSenderDataProvider::getAddressInEmailAngleFormat(
			email: $sender['email'],
			senderName: $sender['name'] ?? '',
			userId: $userId,
		);
	}

	/**
	 * Older kernels have no identity and keep selecting the transport by the address.
	 *
	 */
	private function applySenderIdentity(Context $context, ?Sender\Identity $identity): void
	{
		if ($identity !== null && method_exists($context, 'setSenderIdentity'))
		{
			$context->setSenderIdentity($identity);
		}
	}

	public static function extractEmail(string $address): string
	{
		if (preg_match('/<([^>]+)>/', $address, $matches))
		{
			return mb_strtolower(trim($matches[1]));
		}

		return mb_strtolower(trim($address));
	}

	private static function generateMessageId(): string
	{
		return sprintf('<bx.mail.%x.%x@%s>', time(), rand(0, 0xffffff), self::getHostname());
	}

	private static function getHostname(): string
	{
		if (defined('BX24_HOST_NAME') && BX24_HOST_NAME !== '')
		{
			return BX24_HOST_NAME;
		}

		if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '')
		{
			return SITE_SERVER_NAME;
		}

		return \COption::getOptionString('main', 'server_name', '') ?: 'localhost';
	}

	/**
	 * @param string[] $recipients
	 * @param string[] $cc
	 * @param string[] $bcc
	 * @param array<string, string> $extraHeaders
	 * @return array{success: bool, to: string[]}
	 * @throws SystemException
	 */
	private function doSend(
		string $from,
		array $recipients,
		string $subject,
		string $body,
		int $userId,
		array $cc = [],
		array $bcc = [],
		array $attachments = [],
		?string $inReplyTo = null,
		?int $senderId = null,
		?int $mailboxId = null,
		array $extraHeaders = [],
	): array
	{
		if ($this->transport !== null)
		{
			$body = self::prepareHtmlBody($body);

			return ($this->transport)(get_defined_vars());
		}

		$emailsLimitToSendMessage = Helper\LicenseManager::getEmailsLimitToSendMessage();
		if (
			$emailsLimitToSendMessage !== -1
			&& (
				count($recipients) > $emailsLimitToSendMessage
				|| count($cc) > $emailsLimitToSendMessage
				|| count($bcc) > $emailsLimitToSendMessage
			)
		)
		{
			throw new SystemException(sprintf(
				'Tariff restriction: each of to, cc, bcc must contain at most %d recipient(s).',
				$emailsLimitToSendMessage,
			));
		}

		$totalRecipients = count($recipients) + count($cc) + count($bcc);
		$recipientsTotalLimit = Helper\LicenseManager::getMessageRecipientsTotalLimit();
		if ($totalRecipients > $recipientsTotalLimit)
		{
			throw new SystemException(sprintf(
				'Total number of recipients (to + cc + bcc) is %d, must not exceed %d.',
				$totalRecipients,
				$recipientsTotalLimit,
			));
		}

		if (empty($recipients))
		{
			throw new SystemException('No valid recipients provided.');
		}

		$senderSelection = $this->resolveSenderSelection($from, $userId, $senderId, $mailboxId);
		$senderData = $senderSelection['sender'];
		$sender = $this->formatSender($senderData, $userId);
		self::assertNoControlCharacters($sender, 'From');

		$senderEmail = self::extractEmail($sender);
		$senderIdentity = $senderSelection['identity'];

		if ($this->isSenderLimitReached($senderEmail, $totalRecipients, $senderIdentity))
		{
			throw new SystemException('Daily sender email limit reached.');
		}

		if ($this->isDailyPortalLimitReached($totalRecipients))
		{
			throw new SystemException('Daily portal mail limit reached.');
		}

		if ($this->isMonthPortalLimitReached($totalRecipients))
		{
			throw new SystemException('Monthly portal mail limit reached.');
		}

		$signatureImagePreparation = self::signatureImagePreparation();
		$preparedBody = self::prepareHtmlBody($body);
		$signatureImageResult = $signatureImagePreparation->prepare(
			$preparedBody,
			$userId,
			self::getHostname(),
			$attachments,
			$this->getAttachmentsSize($attachments),
		);
		if (!$signatureImageResult->isSuccess())
		{
			throw new SystemException($signatureImageResult->getErrors()[0]->getMessage());
		}

		$signatureImageSnapshot = $signatureImageResult->getData();
		$attachments = (array)$signatureImageSnapshot['attachments'];
		$recipientString = self::joinAddresses($recipients, 'To');

		$outgoingParams = [
			'TO' => $recipientString,
			'SUBJECT' => self::stripControlCharacters($subject),
			'BODY' => (string)$signatureImageSnapshot['html'],
			'HEADER' => [
				'From' => $sender,
				'Reply-To' => $sender,
				'Message-Id' => self::generateMessageId(),
			],
			'CHARSET' => 'UTF-8',
			'CONTENT_TYPE' => 'html',
		];

		if (!empty($attachments))
		{
			$outgoingParams['ATTACHMENT'] = $attachments;
		}

		if (!empty($cc))
		{
			$outgoingParams['HEADER']['Cc'] = self::joinAddresses($cc, 'Cc');
		}

		if (!empty($bcc))
		{
			$outgoingParams['HEADER']['Bcc'] = self::joinAddresses($bcc, 'Bcc');
		}

		$inReplyTo = self::prepareInReplyTo($inReplyTo);
		if ($inReplyTo !== null)
		{
			$outgoingParams['HEADER']['In-Reply-To'] = $inReplyTo;
		}

		$outgoingParams['HEADER'] = array_merge(
			$outgoingParams['HEADER'],
			self::prepareExtraHeaders($extraHeaders),
		);

		$mailboxHelper = $this->resolveMailboxHelper($senderEmail, $userId, $senderIdentity);

		if ($mailboxHelper !== null)
		{
			$this->assertMailboxSendingAllowed($mailboxHelper);

			if (!$mailboxHelper->isAuthenticated())
			{
				throw new SystemException('Mailbox authentication failed.');
			}

			// A mailbox prints the headers itself instead of leaving the envelope to the core, so the two
			// fields become header lines here - already carrying the values the assembly above cleared
			$mailboxHelper->mail(array_merge(
				$outgoingParams,
				[
					'HEADER' => array_merge(
						$outgoingParams['HEADER'],
						[
							'To' => $outgoingParams['TO'],
							'Subject' => $outgoingParams['SUBJECT'],
						],
					),
				],
			));

			return [
				'success' => true,
				'to' => $recipients,
			];
		}

		$context = new Context();
		$context->setCategory(Context::CAT_EXTERNAL);
		$context->setPriority(
			count($recipients) > 2 ? Context::PRIORITY_LOW : Context::PRIORITY_NORMAL,
		);
		$this->applySenderIdentity($context, $senderIdentity);

		$mailParams = array_merge($outgoingParams, [
			'CONTEXT' => $context,
		]);
		if (method_exists(Mail::class, 'sendResult'))
		{
			$sendResult = Mail::sendResult($mailParams);
			$success = $sendResult->isSuccess();
		}
		else
		{
			$sendResult = null;
			$success = Mail::send($mailParams);
		}

		if (!$success)
		{
			throw new SystemException(
				$sendResult?->getError()?->getMessage() ?? 'Failed to send email.',
			);
		}

		return [
			'success' => true,
			'to' => $recipients,
		];
	}

	/**
	 * Extra headers of the caller, ready to join the assembled ones. A name that is not the caller's to
	 * write is dropped, and a value carrying a control character is an attempt to smuggle a header of its
	 * own, so the letter is not sent at all.
	 *
	 * @param array<string, string> $extraHeaders
	 * @return array<string, string>
	 * @throws SystemException
	 */
	private static function prepareExtraHeaders(array $extraHeaders): array
	{
		$prepared = [];

		foreach ($extraHeaders as $name => $value)
		{
			// A list instead of a map means the caller wrote whole header lines: its keys are positions of
			// an array and not names of headers
			$name = is_string($name) ? trim($name) : '';
			if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1)
			{
				throw new SystemException('Invalid header name provided.');
			}

			if (!self::isHeaderNameAllowed($name))
			{
				continue;
			}

			if (!is_string($value))
			{
				throw new SystemException(sprintf('Invalid value of the %s header.', $name));
			}

			self::assertNoControlCharacters($value, $name);

			$prepared[$name] = $value;
		}

		return $prepared;
	}

	/**
	 * The value with the characters that would end its header line taken out. This is the answer of the
	 * send path for the subject: it is written by the sender of the letter being answered, so a control
	 * character costs the character and not the send - refusing would hand anyone outside the portal a
	 * way to silence an auto-reply.
	 */
	public static function stripControlCharacters(string $value): string
	{
		return (string)preg_replace(self::CONTROL_CHARACTER_PATTERN, '', $value);
	}

	/**
	 * `In-Reply-To` of the answer, or nothing at all. The identifier is a `Message-Id` of the letter being
	 * answered, that is a value of its sender: one carrying a control character is no identifier, and
	 * cutting the character would leave the answer tied to a conversation that never existed.
	 */
	private static function prepareInReplyTo(?string $inReplyTo): ?string
	{
		if ($inReplyTo === null || self::hasControlCharacters($inReplyTo))
		{
			return null;
		}

		return $inReplyTo;
	}

	/**
	 * Addresses of the caller as one header line. An address is the caller's own value and a control
	 * character has no place in it, so the letter is not sent rather than sent along a line the caller
	 * never wrote.
	 *
	 * @param string[] $addresses
	 * @throws SystemException
	 */
	private static function joinAddresses(array $addresses, string $headerName): string
	{
		$line = implode(', ', $addresses);
		self::assertNoControlCharacters($line, $headerName);

		return $line;
	}

	/**
	 * @throws SystemException
	 */
	private static function assertNoControlCharacters(string $value, string $headerName): void
	{
		if (self::hasControlCharacters($value))
		{
			throw new SystemException(sprintf('Invalid value of the %s header.', $headerName));
		}
	}

	private static function hasControlCharacters(string $value): bool
	{
		return preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1;
	}

	/**
	 * Is this header the caller's to write. The letter still goes out without a header that is not: a
	 * caller asking for one is not an attempt to forge the envelope, unlike a value with a line break in
	 * it.
	 */
	private static function isHeaderNameAllowed(string $name): bool
	{
		$name = mb_strtolower($name);

		if (in_array($name, self::CORE_X_HEADER_NAMES, true))
		{
			return false;
		}

		return in_array($name, self::ALLOWED_HEADER_NAMES, true)
			|| str_starts_with($name, self::ALLOWED_HEADER_NAME_PREFIX)
		;
	}

	private function resolveMailboxHelper(
		string $senderEmail,
		int $userId,
		?Sender\Identity $identity,
	): ?Helper\Mailbox
	{
		foreach (MailboxTable::getUserMailboxes($userId) as $mailbox)
		{
			if (
				mb_strtolower($mailbox['EMAIL'] ?? '') === $senderEmail
				&& (
					$identity === null
					|| ($identity->hasMailboxRef() && (int)$mailbox['ID'] === $identity->mailboxParentId)
				)
			)
			{
				$instance = Helper\Mailbox::createInstance($mailbox['ID'], false);

				return $instance instanceof Helper\Mailbox ? $instance : null;
			}
		}

		return null;
	}

	private function assertMailboxSendingAllowed(Helper\Mailbox $mailboxHelper): void
	{
		$this->assertMailboxSendingAllowedById($mailboxHelper->getMailboxId());
	}

	private function assertMailboxSendingAllowedById(int $mailboxId): void
	{
		if ($mailboxId <= 0)
		{
			return;
		}

		$guard = (new MigrationActionGuard())->check($mailboxId);
		if (!$guard->isSuccess())
		{
			throw new MailboxMigrationActionException(
				(string)$guard->getErrors()[0]->getCode(),
			);
		}
	}

	/**
	 * @throws SystemException
	 */
	private function getOriginalMessage(int $messageId, int $userId): array
	{
		$message = $this->sourceProvider->loadMessage($messageId);

		if (!$message || !$this->sourceProvider->hasAccess($message, $userId))
		{
			throw new SystemException('Original message not found or access denied.');
		}

		if (
			(int)($message['OPTIONS']['attachments'] ?? 0) > 0
			&& (int)($message['ATTACHMENTS'] ?? 0) < (int)$message['OPTIONS']['attachments']
		)
		{
			if ((int)($message['ATTACHMENTS'] ?? 0) > 0)
			{
				throw new SystemException('Original message not found or access denied.');
			}

			$message = $this->sourceProvider->materialize($message);
			if ($message === null || !$this->sourceProvider->hasAccess($message, $userId))
			{
				throw new SystemException('Original message not found or access denied.');
			}
		}

		return $message;
	}

	private function buildQuote(array $originalMessage): string
	{
		$originalBody = $originalMessage['BODY_HTML'] ?? $originalMessage['BODY'] ?? '';
		[$protectedBody, $protectedSources] = self::protectSourcesForSanitizer(
			(string)$originalBody,
			self::INLINE_ATTACHMENT_SOURCE_PATTERN,
			static fn(array $match): string => 'aid:' . ($match[3] ?: ($match[4] ?: $match[5])),
		);
		[$protectedBody, $protectedCidSources] = self::protectSourcesForSanitizer(
			$protectedBody,
			self::CID_SOURCE_PATTERN,
			self::resolveCidSource(...),
		);
		$protectedSources += $protectedCidSources;
		$sanitizedBody = $this->bodySanitizer !== null
			? ($this->bodySanitizer)($protectedBody)
			: Helper\Message::sanitizeHtml($protectedBody)
		;
		$sanitizedBody = strtr($sanitizedBody, $protectedSources);
		if ($this->quoteBuilder !== null)
		{
			return ($this->quoteBuilder)($originalMessage, $sanitizedBody);
		}
		$from = self::parseAddressField($originalMessage['FIELD_FROM'] ?? '');
		$to = self::parseAddressField($originalMessage['FIELD_TO'] ?? '');
		$cc = self::parseAddressField($originalMessage['FIELD_CC'] ?? '');

		return MailMessage::wrapTheMessageWithAQuote(
			$sanitizedBody,
			$originalMessage['SUBJECT'] ?? '',
			(string)($originalMessage['FIELD_DATE'] ?? ''),
			$from,
			$to,
			$cc,
			true,
		);
	}

	private function combineUserBodyWithQuote(string $userBody, string $quote): string
	{
		if (trim($userBody) !== '')
		{
			return $userBody . '<br><br>' . $quote;
		}

		return $quote;
	}

	/**
	 * @return array<int, array{name: string, email: string}>
	 */
	private static function parseAddressField(string $field): array
	{
		$result = [];

		foreach (explode(',', $field) as $item)
		{
			$item = trim($item);
			if ($item === '')
			{
				continue;
			}

			$address = new Address($item);
			if ($address->validate())
			{
				$result[] = [
					'name' => $address->getName(),
					'email' => $address->getEmail(),
				];
			}
		}

		return $result;
	}

	/**
	 * @return array{0: array<int, array{ID: string, NAME: string, PATH: string, CONTENT_TYPE: string, RELATED: bool}>, 1: string}
	 * @throws SystemException
	 */
	private function getMessageAttachments(
		int $messageId,
		string $body,
		bool $inlineOnly,
		int $expectedAttachments,
	): array
	{
		$rows = $this->sourceProvider->loadAttachmentRows($messageId);
		$unresolvedInlineIds = array_fill_keys($this->findInlineAttachmentIds($body), true);

		$localRows = array_values(array_filter(
			$rows,
			static fn(array $row): bool => empty($row['EXTERNAL_LINK_ID']),
		));
		$expectedLocalAttachments = max(0, $expectedAttachments - (count($rows) - count($localRows)));
		if (!$inlineOnly && count($localRows) < $expectedLocalAttachments)
		{
			$this->failOnUnreadableStoredAttachment($messageId);
		}

		$attachments = [];
		$inlineContentIds = [];
		$hostname = $this->hostnameResolver !== null ? ($this->hostnameResolver)() : self::getHostname();
		foreach ($localRows as $row)
		{
			$attachmentId = (int)($row['ID'] ?? 0);
			$isInline = isset($unresolvedInlineIds[$attachmentId]);
			if ($inlineOnly && !$isInline)
			{
				continue;
			}

			$fileArray = $this->sourceProvider->resolveFile((int)($row['FILE_ID'] ?? 0));
			if (
				!is_array($fileArray)
				|| empty($fileArray['tmp_name'])
				|| !is_file($fileArray['tmp_name'])
				|| !is_readable($fileArray['tmp_name'])
			)
			{
				$this->failOnUnreadableStoredAttachment($messageId);
			}

			$contentId = self::buildAttachmentContentId($attachmentId, $hostname);
			if ($isInline)
			{
				$inlineContentIds[$attachmentId] = $contentId;
				unset($unresolvedInlineIds[$attachmentId]);
			}

			$attachments[] = [
				'ID' => $contentId,
				'NAME' => $row['FILE_NAME'],
				'PATH' => $fileArray['tmp_name'],
				'CONTENT_TYPE' => $row['CONTENT_TYPE'] ?: ($fileArray['type'] ?? 'application/octet-stream'),
				'RELATED' => $isInline,
			];
		}

		if ($unresolvedInlineIds !== [])
		{
			$this->failOnUnreadableStoredAttachment($messageId);
		}

		$body = $this->replaceInlineAttachmentIds($body, $inlineContentIds);

		return [$attachments, $body];
	}

	private static function buildAttachmentContentId(int $attachmentId, string $hostname): string
	{
		return sprintf('bx.mail.attachment.%u@%s.mail', $attachmentId, hash('crc32b', $hostname));
	}

	/**
	 * @return int[]
	 */
	private function findInlineAttachmentIds(string $body): array
	{
		return array_values(array_unique(array_map(
			static fn(array $match): int => $match['attachmentId'],
			$this->matchInlineAttachmentSources($body),
		)));
	}

	/**
	 * @param array<int, string> $contentIds
	 */
	private function replaceInlineAttachmentIds(string $body, array $contentIds): string
	{
		if ($contentIds === [])
		{
			return $body;
		}

		$chunks = [];
		$cursor = 0;
		foreach ($this->matchInlineAttachmentSources($body) as $match)
		{
			$contentId = $contentIds[$match['attachmentId']] ?? null;
			if ($contentId === null)
			{
				continue;
			}

			$source = $match['quote'] . 'cid:' . $contentId . $match['quote'];
			$replacement = $match['attribute'] . $match['separator'] . $source;
			$chunks[] = substr($body, $cursor, $match['offset'] - $cursor);
			$chunks[] = $replacement;
			$cursor = $match['offset'] + $match['length'];
		}

		$chunks[] = substr($body, $cursor);

		return implode('', $chunks);
	}

	/**
	 * @return array<int, array{attachmentId: int, attribute: string, separator: string, quote: string, offset: int, length: int}>
	 */
	private function matchInlineAttachmentSources(string $body): array
	{
		preg_match_all(
			self::INLINE_ATTACHMENT_SOURCE_PATTERN,
			$body,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
		);

		$result = [];
		foreach (self::filterHtmlAttributeMatches($body, $matches) as $match)
		{
			$offset = $match[0][1];
			$doubleQuotedId = $match[3][0] ?? '';
			$singleQuotedId = $match[4][0] ?? '';
			$unquotedId = $match[5][0] ?? '';
			$result[] = [
				'attachmentId' => (int)($doubleQuotedId ?: $singleQuotedId ?: $unquotedId),
				'attribute' => $match[1][0],
				'separator' => $match[2][0],
				'quote' => $doubleQuotedId !== '' ? '"' : ($singleQuotedId !== '' ? '\'' : ''),
				'offset' => $offset,
				'length' => strlen($match[0][0]),
			];
		}

		return $result;
	}

	private static function findHtmlTagRanges(string $body): array
	{
		$insideTag = false;
		$quote = null;
		$tagStart = 0;
		$ranges = [];
		for ($position = 0, $length = strlen($body); $position < $length; $position++)
		{
			$character = $body[$position];
			if (!$insideTag)
			{
				if ($character === '<')
				{
					$insideTag = true;
					$tagStart = $position;
				}

				continue;
			}

			if ($quote !== null)
			{
				if ($character === $quote)
				{
					$quote = null;
				}

				continue;
			}

			if ($character === '"' || $character === '\'')
			{
				$quote = $character;
			}
			elseif ($character === '>')
			{
				$ranges[] = [$tagStart, $position];
				$insideTag = false;
			}
			elseif ($character === '<')
			{
				$tagStart = $position;
			}
		}

		return $ranges;
	}

	/**
	 * @throws SystemException
	 */
	private function failOnUnreadableStoredAttachment(int $messageId): never
	{
		throw new SystemException('Original message not found or access denied.');
	}

	private function getAttachmentsSize(array $attachments): int
	{
		$totalSize = 0;
		foreach ($attachments as $attachment)
		{
			$path = (string)($attachment['PATH'] ?? '');
			if ($path !== '' && is_file($path))
			{
				$totalSize += (int)filesize($path);
			}
		}

		return $totalSize;
	}

	/**
	 * The single point where the preparation of signature images is built: every path that cleans an
	 * outgoing body goes through it, so the markers are normalized the same way everywhere.
	 */
	private static function signatureImagePreparation(): SignatureImageSendPreparation
	{
		return new SignatureImageSendPreparation();
	}

	private static function prepareHtmlBody(string $body): string
	{
		[$body, $protectedSources] = self::protectSourcesForSanitizer(
			$body,
			self::CID_SOURCE_PATTERN,
			self::resolveCidSource(...),
		);

		$html = strtr(self::signatureImagePreparation()->sanitizeHtml($body), $protectedSources);

		if (mb_strpos($html, '</html>') === false)
		{
			$html = '<html><body>' . $html . '</body></html>';
		}

		return $html;
	}

	private static function protectSourcesForSanitizer(
		string $body,
		string $pattern,
		\Closure $sourceResolver,
	): array
	{
		$protectedSources = [];
		preg_match_all(
			$pattern,
			$body,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
		);

		$chunks = [];
		$cursor = 0;
		foreach (self::filterHtmlAttributeMatches($body, $matches) as $match)
		{
			$offset = $match[0][1];
			$length = strlen($match[0][0]);
			$plainMatch = array_map(
				static fn(array $capture): string => $capture[0] ?? '',
				$match,
			);
			$source = $sourceResolver($plainMatch);
			if ($source === null)
			{
				$replacement = $plainMatch[1] . $plainMatch[2] . '""';
			}
			else
			{
				$token = self::SANITIZER_SOURCE_PLACEHOLDER_PREFIX . bin2hex(random_bytes(16));
				$protectedSources[$token] = htmlspecialchars(
					$source,
					ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5,
					'UTF-8',
					false,
				);
				$replacement = $plainMatch[1] . $plainMatch[2] . '"' . $token . '"';
			}

			$chunks[] = substr($body, $cursor, $offset - $cursor);
			$chunks[] = $replacement;
			$cursor = $offset + $length;
		}

		$chunks[] = substr($body, $cursor);

		return [implode('', $chunks), $protectedSources];
	}

	private static function filterHtmlAttributeMatches(string $body, array $matches): array
	{
		$result = [];
		$tagRanges = self::findHtmlTagRanges($body);
		$tagIndex = 0;
		$contextPosition = null;
		$quote = null;
		foreach ($matches as $match)
		{
			$offset = $match[0][1];
			$length = strlen($match[0][0]);
			while (isset($tagRanges[$tagIndex]) && $tagRanges[$tagIndex][1] < $offset)
			{
				$tagIndex++;
				$contextPosition = null;
				$quote = null;
			}
			if (
				!isset($tagRanges[$tagIndex])
				|| $tagRanges[$tagIndex][0] >= $offset
				|| $tagRanges[$tagIndex][1] < $offset + $length
			)
			{
				continue;
			}

			$contextPosition ??= $tagRanges[$tagIndex][0] + 1;
			for (; $contextPosition < $offset; $contextPosition++)
			{
				$character = $body[$contextPosition];
				if ($character !== '"' && $character !== '\'')
				{
					continue;
				}

				$quote = $quote === null ? $character : ($quote === $character ? null : $quote);
			}

			if ($quote === null)
			{
				$result[] = $match;
			}
		}

		return $result;
	}

	private static function resolveCidSource(array $match): ?string
	{
		$context = $match[3] !== '' ? 'double' : ($match[4] !== '' ? 'single' : 'unquoted');
		$source = html_entity_decode(
			trim($match[3] ?: ($match[4] ?: $match[5])),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8',
		);
		$patterns = [
			'double' => '/\Acid:[A-Za-z0-9.!#$%&\'*+\-\/=\?^_{}|~@]+\z/iD',
			'single' => '/\Acid:[A-Za-z0-9.!#$%&"*+\-\/=\?^_{}|~@]+\z/iD',
			'unquoted' => '/\Acid:[A-Za-z0-9.!#$%&*+\-\/\?^_{}|~@]+\z/iD',
		];

		return preg_match($patterns[$context], $source) === 1 ? $source : null;
	}

	/**
	 * The limit belongs to the sender record the message goes through, the way the kernel reads it on
	 * send: an address-wide check would refuse the message because of the quota of another owner.
	 */
	private function isSenderLimitReached(
		string $fromEmail,
		int $recipientsCount,
		?Sender\Identity $identity,
	): bool
	{
		$emailDailyLimit = Sender::getEmailLimit($fromEmail, $identity);
		if ($emailDailyLimit <= 0)
		{
			return false;
		}

		$emailCounter = new SenderSendCounter();
		$limit = $emailCounter->get($fromEmail);

		return ($limit + $recipientsCount) > $emailDailyLimit;
	}

	private function isDailyPortalLimitReached(int $recipientsCount): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$counter = new \Bitrix\Bitrix24\MailCounter();
		$limit = $counter->getDailyLimit();

		return $limit > 0 && \Bitrix\Bitrix24\MailCounter::checkLimit($limit, $counter->get() + $recipientsCount);
	}

	private function isMonthPortalLimitReached(int $recipientsCount): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$counter = new \Bitrix\Bitrix24\MailCounter();
		$limit = $counter->getLimit();

		return $limit > 0 && \Bitrix\Bitrix24\MailCounter::checkLimit($limit, $counter->getMonthly() + $recipientsCount);
	}
}
