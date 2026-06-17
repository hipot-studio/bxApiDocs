<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageTable;
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
	public const MAX_TOTAL_RECIPIENTS = 10;

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
	): array
	{
		$originalMessage = $this->getOriginalMessage($messageId, $userId);
		$body = $this->buildQuotedBody($body, $originalMessage);
		$attachments = $this->getMessageAttachments($messageId);

		return $this->doSend(
			from: $from,
			recipients: $recipients,
			subject: $subject,
			body: $body,
			userId: $userId,
			cc: $cc,
			bcc: $bcc,
			attachments: $attachments,
		);
	}

	/**
	 * @param string[] $recipients
	 * @param string[] $cc
	 * @param string[] $bcc
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
	): array
	{
		$originalMessage = $this->getOriginalMessage($messageId, $userId);
		$body = $this->buildQuotedBody($body, $originalMessage);
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
			inReplyTo: $inReplyTo,
		);
	}

	/**
	 * @throws SystemException
	 */
	public function resolveSender(string $from, int $userId): string
	{
		$email = self::extractEmail($from);

		$senders = UserSenderDataProvider::getUserAvailableSenders(
			userId: $userId,
		);

		foreach ($senders as $sender)
		{
			if (mb_strtolower($sender['email'] ?? '') === $email)
			{
				return UserSenderDataProvider::getAddressInEmailAngleFormat(
					email: $sender['email'],
					senderName: $sender['name'] ?? '',
					userId: $userId,
				);
			}
		}

		throw new SystemException('Sender email is not available for this user.');
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
	): array
	{
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
		if ($totalRecipients > self::MAX_TOTAL_RECIPIENTS)
		{
			throw new SystemException(sprintf(
				'Total number of recipients (to + cc + bcc) is %d, must not exceed %d.',
				$totalRecipients,
				self::MAX_TOTAL_RECIPIENTS,
			));
		}

		if (empty($recipients))
		{
			throw new SystemException('No valid recipients provided.');
		}

		$sender = $this->resolveSender($from, $userId);
		$senderEmail = self::extractEmail($sender);

		if ($this->isSenderLimitReached($senderEmail, $totalRecipients))
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

		$recipientString = implode(', ', $recipients);

		$outgoingParams = [
			'TO' => $recipientString,
			'SUBJECT' => $subject,
			'BODY' => self::prepareHtmlBody($body),
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
			$outgoingParams['HEADER']['Cc'] = implode(', ', $cc);
		}

		if (!empty($bcc))
		{
			$outgoingParams['HEADER']['Bcc'] = implode(', ', $bcc);
		}

		if ($inReplyTo !== null)
		{
			$outgoingParams['HEADER']['In-Reply-To'] = $inReplyTo;
		}

		$mailboxHelper = $this->resolveMailboxHelper($senderEmail, $userId);

		if ($mailboxHelper !== null)
		{
			if (!$mailboxHelper->isAuthenticated())
			{
				throw new SystemException('Mailbox authentication failed.');
			}

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

		$success = Mail::send(array_merge($outgoingParams, [
			'CONTEXT' => $context,
		]));

		if (!$success)
		{
			throw new SystemException('Failed to send email.');
		}

		return [
			'success' => true,
			'to' => $recipients,
		];
	}

	private function resolveMailboxHelper(string $senderEmail, int $userId): ?Helper\Mailbox
	{
		foreach (MailboxTable::getUserMailboxes($userId) as $mailbox)
		{
			if (mb_strtolower($mailbox['EMAIL'] ?? '') === $senderEmail)
			{
				$instance = Helper\Mailbox::createInstance($mailbox['ID'], false);

				return $instance instanceof Helper\Mailbox ? $instance : null;
			}
		}

		return null;
	}

	/**
	 * @throws SystemException
	 */
	private function getOriginalMessage(int $messageId, int $userId): array
	{
		$message = MailMessageTable::query()
			->setSelect(['*', 'MAILBOX_EMAIL' => 'MAILBOX.EMAIL'])
			->where('ID', $messageId)
			->setLimit(1)
			->fetch()
		;

		if (!$message || !Helper\Message::hasAccess($message, $userId))
		{
			throw new SystemException('Original message not found or access denied.');
		}

		Helper\Message::ensureAttachments($message);

		return $message;
	}

	private function buildQuotedBody(string $userBody, array $originalMessage): string
	{
		$originalBody = $originalMessage['BODY_HTML'] ?? $originalMessage['BODY'] ?? '';
		$from = self::parseAddressField($originalMessage['FIELD_FROM'] ?? '');
		$to = self::parseAddressField($originalMessage['FIELD_TO'] ?? '');
		$cc = self::parseAddressField($originalMessage['FIELD_CC'] ?? '');

		$quote = MailMessage::wrapTheMessageWithAQuote(
			$originalBody,
			$originalMessage['SUBJECT'] ?? '',
			(string)($originalMessage['FIELD_DATE'] ?? ''),
			$from,
			$to,
			$cc,
		);

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
	 * @return array<int, array{ID: string, NAME: string, PATH: string, CONTENT_TYPE: string}>
	 */
	private function getMessageAttachments(int $messageId): array
	{
		$rows = MailMessageAttachmentTable::query()
			->setSelect(['ID', 'FILE_ID', 'FILE_NAME', 'FILE_SIZE', 'CONTENT_TYPE'])
			->where('MESSAGE_ID', $messageId)
			->fetchAll()
		;

		$attachments = [];
		foreach ($rows as $row)
		{
			$fileArray = \CFile::makeFileArray($row['FILE_ID']);
			if (!$fileArray)
			{
				continue;
			}

			$attachments[] = [
				'ID' => $row['FILE_NAME'],
				'NAME' => $row['FILE_NAME'],
				'PATH' => $fileArray['tmp_name'],
				'CONTENT_TYPE' => $row['CONTENT_TYPE'] ?: ($fileArray['type'] ?? 'application/octet-stream'),
			];
		}

		return $attachments;
	}

	private static function prepareHtmlBody(string $body): string
	{
		$sanitizer = new \CBXSanitizer();
		$sanitizer->setLevel(\CBXSanitizer::SECURE_LEVEL_LOW);
		$sanitizer->applyDoubleEncode(false);
		$sanitizer->addTags(Helper\Message::getWhitelistTagAttributes());

		$html = $sanitizer->sanitizeHtml($body);

		if (mb_strpos($html, '</html>') === false)
		{
			$html = '<html><body>' . $html . '</body></html>';
		}

		return $html;
	}

	private function isSenderLimitReached(string $fromEmail, int $recipientsCount): bool
	{
		$emailDailyLimit = Sender::getEmailLimit($fromEmail);
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
