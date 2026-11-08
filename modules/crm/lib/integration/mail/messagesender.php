<?php

namespace Bitrix\Crm\Integration\Mail;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Service\Message\SendFailure;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\Result;
use CCrmActivity;
use CCrmEMailCodeAllocation;

class MessageSender
{
	private const CONTROLLED_TRANSPORT_ERROR_CODES = [
		'MAIL_SENDER_ADDRESS_MISMATCH',
		'MAIL_SENDER_UNAVAILABLE',
	];

	private static array $availableSendersByUserId = [];

	/**
	 * @param array $input {
	 * @param string $emptySubjectPlaceholder Fallback when body cannot produce a subject.
	 * @param Helper\Mailbox|null $mailboxHelper
	 * @param Result|null $transportResult Detailed transport outcome for callers that can display controlled errors.
	 * @param int|null $senderId Selected standalone sender record. Ignored when a mailbox is selected.
	 * @param int|null $senderUserId User whose available sender list must contain the standalone record.
	 * @return bool|array Mail::send return value.
	 * @throws ArgumentException
	 * @var string $subject Finalized subject (already passed through getOutgoingSubject).
	 * @var string $body HTML body.
	 * @var string[] $to TO recipients (raw).
	 * @var string[] $cc CC recipients (raw).
	 * @var string[] $bcc BCC recipients (raw).
	 * @var string $fromEmail Raw "from" email.
	 * @var string $fromEncoded Encoded "from" header.
	 * @var string $reply Encoded "reply-to" header.
	 * @var array $rawFiles Raw file descriptors for attachments.
	 * @var array<int,int> $attachToFileIds Optional map of attachment key => bxacid index (legacy).
	 * @var string $urn Activity URN used for tracking and Message-Id callback.
	 * @var bool $injectUrn Whether to inject URN into subject or body.
	 * @var string $hostname Hostname for CID domain.
	 * @var string $messageId Message-Id header value.
	 * @var int $priorityCount Recipient count used to decide priority.
	 * }
	 */
	public static function send(
		array $input,
		string $emptySubjectPlaceholder,
		?Helper\Mailbox $mailboxHelper = null,
		?Result &$transportResult = null,
		?int $senderId = null,
		?int $senderUserId = null,
	): bool|array
	{
		$rcpt    = [];
		$rcptCc  = [];
		$rcptBcc = [];
		foreach ($input['to'] ?? [] as $item)
		{
			$rcpt[] = Mail\Mail::encodeHeaderFrom($item, SITE_CHARSET);
		}
		foreach ($input['cc'] ?? [] as $item)
		{
			$rcptCc[] = Mail\Mail::encodeHeaderFrom($item, SITE_CHARSET);
		}
		foreach ($input['bcc'] ?? [] as $item)
		{
			$rcptBcc[] = Mail\Mail::encodeHeaderFrom($item, SITE_CHARSET);
		}

		$outgoingSubject = (string)($input['subject'] ?? '');
		$outgoingBody    = (string)($input['body'] ?? '');

		if (!empty($input['injectUrn']))
		{
			switch (CCrmEMailCodeAllocation::getCurrent())
			{
				case CCrmEMailCodeAllocation::Subject:
					$outgoingSubject = CCrmActivity::injectUrnInSubject($input['urn'], $outgoingSubject);
					break;
				case CCrmEMailCodeAllocation::Body:
					$outgoingBody = CCrmActivity::injectUrnInBody($input['urn'], $outgoingBody, 'html');
					break;
			}
		}

		$attachments = [];
		$attachToFileIds = $input['attachToFileIds'] ?? [];
		foreach ($input['rawFiles'] ?? [] as $key => $item)
		{
			$contentId = sprintf(
				'bxacid.%s@%s.crm',
				hash('crc32b', $item['external_id'].$item['size'].$item['name']),
				hash('crc32b', (string)($input['hostname'] ?? '')),
			);

			$attachments[] = [
				'ID'           => $contentId,
				'NAME'         => $item['ORIGINAL_NAME'] ?: $item['name'],
				'PATH'         => $item['tmp_name'],
				'CONTENT_TYPE' => $item['type'],
			];

			$bxacidKey = $attachToFileIds[$key] ?? $key;
			$outgoingBody = preg_replace(
				sprintf('/(https?:\/\/)?bxacid:n?%u/i', $bxacidKey),
				sprintf('cid:%s', $contentId),
				$outgoingBody,
			);
		}

		$outgoingParams = [
			'CHARSET'      => SITE_CHARSET,
			'CONTENT_TYPE' => 'html',
			'ATTACHMENT'   => $attachments,
			'TO'           => implode(', ', $rcpt),
			'SUBJECT'      => $outgoingSubject,
			'BODY'         => $outgoingBody,
			'HEADER'       => [
				'From'       => $input['fromEncoded'] ?: $input['fromEmail'],
				'Reply-To'   => $input['reply'] ?: $input['fromEmail'],
				'Cc'         => implode(', ', $rcptCc),
				'Bcc'        => implode(', ', $rcptBcc),
				'Message-Id' => $input['messageId'],
			],
		];

		$context = new Mail\Context();
		$context->setCategory(Mail\Context::CAT_EXTERNAL);
		$context->setPriority(
			($input['priorityCount'] ?? 0) > 2
				? Mail\Context::PRIORITY_LOW
				: Mail\Context::PRIORITY_NORMAL,
		);
		$context->setCallback(
			(new Mail\Callback\Config())
				->setModuleId('crm')
				->setEntityType('act')
				->setEntityId($input['urn']),
		);
		$identityApplied = self::applySenderIdentity(
			$context,
			(int)$mailboxHelper?->getMailboxId(),
			$senderId,
			(string)($input['fromEmail'] ?? ''),
			$senderUserId,
		);
		if (!$identityApplied)
		{
			$transportResult = self::createSenderUnavailableResult();

			return false;
		}

		$outgoingParams['SUBJECT'] = Helper\Message::getOutgoingSubject(
			$outgoingParams['SUBJECT'],
			$outgoingParams['BODY'],
			$emptySubjectPlaceholder,
		);

		$mailParams = array_merge(
			$outgoingParams,
			[
				'TRACK_READ' => [
					'MODULE_ID' => 'crm',
					'FIELDS'    => ['urn' => $input['urn']],
					'URL_PAGE'  => '/pub/mail/read.php',
				],
				'TRACK_CLICK' => [
					'MODULE_ID' => 'crm',
					'FIELDS'    => ['urn' => $input['urn']],
					'URL_PAGE'  => '/pub/mail/click.php',
				],
				'CONTEXT' => $context,
			],
		);
		if (method_exists(Mail\Mail::class, 'sendResult'))
		{
			$transportResult = Mail\Mail::sendResult($mailParams);
			$result = $transportResult->isSuccess();
		}
		else
		{
			$result = Mail\Mail::send($mailParams);
		}

		if ($result && $mailboxHelper !== null)
		{
			self::uploadToSentFolder($mailboxHelper, $context, $outgoingParams);
		}

		return $result;
	}

	public static function getControlledTransportError(?Result $transportResult): ?Error
	{
		if (self::canUseMailSendFailure('getControlledError'))
		{
			return SendFailure::getControlledError($transportResult);
		}

		if ($transportResult === null || $transportResult->isSuccess())
		{
			return null;
		}

		foreach ($transportResult->getErrors() as $error)
		{
			if (in_array((string)$error->getCode(), self::CONTROLLED_TRANSPORT_ERROR_CODES, true))
			{
				return $error;
			}
		}

		return null;
	}

	/**
	 * Tells the kernel which mailbox or standalone sender owns the message, so that the transport,
	 * the limit and the counter are scoped to its own sender record instead of being picked by the address.
	 * Older kernels have no identity and keep selecting by the address.
	 */
	public static function applySenderIdentity(
		Mail\Context $context,
		int $mailboxId,
		?int $senderId = null,
		?string $fromEmail = null,
		?int $senderUserId = null,
	): bool
	{
		if (
			!class_exists(Mail\Sender\Identity::class)
			|| !method_exists($context, 'setSenderIdentity')
		)
		{
			return true;
		}

		if ($mailboxId > 0)
		{
			$context->setSenderIdentity(Mail\Sender\Identity::fromMailbox($mailboxId));

			return true;
		}

		if ($senderId === null)
		{
			return true;
		}
		if ($senderId <= 0)
		{
			return false;
		}

		$fromAddress = new Mail\Address((string)$fromEmail);
		if (!$fromAddress->validate())
		{
			return false;
		}

		$sender = self::findAvailableSender($senderId, (string)$fromAddress->getEmail(), $senderUserId);
		if ($sender !== null)
		{
			$context->setSenderIdentity(Mail\Sender\Identity::fromSender($senderId));

			return true;
		}

		return false;
	}

	public static function findAvailableSender(int $senderId, string $fromEmail, ?int $userId = null): ?array
	{
		$fromAddress = new Mail\Address($fromEmail);
		if ($senderId <= 0 || !$fromAddress->validate())
		{
			return null;
		}

		$normalizedFrom = mb_strtolower((string)$fromAddress->getEmail());
		foreach (self::getUserAvailableSenders($userId) as $sender)
		{
			if (
				(int)($sender['id'] ?? 0) === $senderId
				&& mb_strtolower((string)($sender['email'] ?? '')) === $normalizedFrom
			)
			{
				return $sender;
			}
		}

		return null;
	}

	public static function findAvailableMailbox(int $mailboxId, string $fromEmail, ?int $userId = null): ?array
	{
		$fromAddress = new Mail\Address($fromEmail);
		if ($mailboxId <= 0 || !$fromAddress->validate())
		{
			return null;
		}

		$normalizedFrom = mb_strtolower((string)$fromAddress->getEmail());
		foreach (self::getUserAvailableSenders($userId) as $sender)
		{
			if (
				(int)($sender['mailboxId'] ?? 0) === $mailboxId
				&& mb_strtolower((string)($sender['email'] ?? '')) === $normalizedFrom
			)
			{
				return $sender;
			}
		}

		return null;
	}

	/** @internal */
	public static function clearAvailableSendersRuntimeCache(): void
	{
		self::$availableSendersByUserId = [];
	}

	private static function getUserAvailableSenders(?int $userId): array
	{
		$userId = $userId > 0
			? $userId
			: (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		if (!array_key_exists($userId, self::$availableSendersByUserId))
		{
			self::$availableSendersByUserId[$userId] = UserSenderDataProvider::getUserAvailableSenderIdentities($userId);
		}

		return self::$availableSendersByUserId[$userId];
	}

	private static function createSenderUnavailableResult(): Result
	{
		if (self::canUseMailSendFailure('createSenderUnavailableError'))
		{
			return (new Result())->addError(SendFailure::createSenderUnavailableError());
		}

		Loc::loadMessages((new \ReflectionClass(Mail\Sender::class))->getFileName());
		$message = (string)Loc::getMessage('MAIN_MAIL_SENDER_UNAVAILABLE_ERROR');

		return (new Result())->addError(new Error($message, Mail\Sender::SENDER_UNAVAILABLE_ERROR));
	}

	/** The refusals are owned by mail; an older mail without them leaves crm with its own copy of the rule. */
	private static function canUseMailSendFailure(string $method): bool
	{
		return class_exists(SendFailure::class) && method_exists(SendFailure::class, $method);
	}

	private static function uploadToSentFolder(
		Helper\Mailbox $mailboxHelper,
		Mail\Context $context,
		array $outgoingParams,
	): void
	{
		$smtp = $context->getSmtp();
		$providerHost = $smtp?->getHost() ? mb_strtolower($smtp->getHost()) : '';
		if (in_array($providerHost, ['smtp.gmail.com', 'smtp.office365.com'], true))
		{
			// Gmail/Office365 SMTP stores a copy in Sent on its side — uploading would duplicate.
			return;
		}

		class_exists('Bitrix\Mail\Helper');

		$outgoing = new \Bitrix\Mail\DummyMail(array_merge(
			$outgoingParams,
			[
				'HEADER' => array_merge(
					$outgoingParams['HEADER'],
					[
						'To'      => $outgoingParams['TO'],
						'Subject' => $outgoingParams['SUBJECT'],
					],
				),
			],
		));

		$mailboxHelper->uploadMessage($outgoing);
	}
}
