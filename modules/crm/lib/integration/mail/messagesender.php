<?php

namespace Bitrix\Crm\Integration\Mail;

use Bitrix\Mail\Helper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Mail;
use CCrmActivity;
use CCrmEMailCodeAllocation;

class MessageSender
{
	/**
	 * @param array $input {
	 * @param string $emptySubjectPlaceholder Fallback when body cannot produce a subject.
	 * @param Helper\Mailbox|null $mailboxHelper
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

		$outgoingParams['SUBJECT'] = Helper\Message::getOutgoingSubject(
			$outgoingParams['SUBJECT'],
			$outgoingParams['BODY'],
			$emptySubjectPlaceholder,
		);

		$result = Mail\Mail::send(array_merge(
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
		));

		if ($result && $mailboxHelper !== null)
		{
			self::uploadToSentFolder($mailboxHelper, $context, $outgoingParams);
		}

		return $result;
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
