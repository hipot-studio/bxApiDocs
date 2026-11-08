<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Message;

use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Internal\Service\LargeAttachment\LargeAttachmentService;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Context;
use Bitrix\Main\Mail\Mail;
use Bitrix\Main\Result;

final class SendMessageService
{
	public const ERROR_INVALID_AUTHOR = 'invalid_author';
	public const ERROR_INVALID_SENDER = 'sender_unavailable';
	public const ERROR_EMPTY_RECIPIENTS = 'recipient_required';
	public const ERROR_TOO_MANY_RECIPIENTS = 'recipient_limit';
	/** Raised by the caller that owns the mailbox: reaching the server is not part of validating a command. */
	public const ERROR_MAILBOX_UNAVAILABLE = 'mailbox_unavailable';
	public const ERROR_SEND_FAILED = 'send_failed';
	public const ERROR_INVALID_HEADER = 'invalid_header';

	public function send(SendMessageCommand $command, ?callable $onQueued = null): Result
	{
		$result = $this->validate($command);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$params = $this->buildOutgoingParams($command, withEnvelopeHeaders: $command->mailbox !== null);
		if ($command->mailbox === null)
		{
			$context = new Context();
			$context->setCategory(Context::CAT_EXTERNAL);
			$context->setPriority(
				count($command->recipients) + count($command->cc) + count($command->bcc) > 2
					? Context::PRIORITY_LOW
					: Context::PRIORITY_NORMAL,
			);
			if ($command->senderIdentity !== null && method_exists($context, 'setSenderIdentity'))
			{
				$context->setSenderIdentity($command->senderIdentity);
			}

			$mailParams = array_merge($params, ['CONTEXT' => $context]);
			if (method_exists(Mail::class, 'sendResult'))
			{
				$sendResult = Mail::sendResult($mailParams);
				if (!$sendResult->isSuccess())
				{
					return $result->addErrors($sendResult->getErrors());
				}
			}
			elseif (!Mail::send($mailParams))
			{
				return $result->addError(new Error('Failed to send email.', self::ERROR_SEND_FAILED));
			}

			return $result->setData(['messageBindings' => []]);
		}

		$eventManager = EventManager::getInstance();
		$messageBindings = [];
		$eventKey = $eventManager->addEventHandler(
			'mail',
			'onBeforeUserFieldSave',
			static function (Event $event) use (&$messageBindings): void
			{
				$messageBindings[] = $event->getParameter('entity_type');
			},
		);

		$markerEventKey = $this->subscribeToLargeAttachmentMarkers($command);
		try
		{
			$command->mailbox->mail($params, $onQueued, $command->authorId);
		}
		finally
		{
			$eventManager->removeEventHandler('mail', 'onBeforeUserFieldSave', $eventKey);
			if ($markerEventKey !== null)
			{
				$eventManager->removeEventHandler('mail', 'onMailMessageNew', $markerEventKey);
			}
		}

		return $result->setData(['messageBindings' => $messageBindings]);
	}

	public function validate(SendMessageCommand $command): Result
	{
		$result = new Result();
		if ($this->containsHeaderControlCharacters($command))
		{
			$result->addError(new Error(
				'Mail header contains invalid characters.',
				self::ERROR_INVALID_HEADER,
			));
		}
		if ($command->authorId <= 0)
		{
			$result->addError(new Error('Author is not available.', self::ERROR_INVALID_AUTHOR));
		}

		$sender = new Address($command->sender);
		if (!$sender->validate())
		{
			$result->addError(new Error('Sender is not available.', self::ERROR_INVALID_SENDER));
		}

		if ($command->recipients === [])
		{
			$result->addError(new Error('At least one recipient is required.', self::ERROR_EMPTY_RECIPIENTS));
		}

		if (
			count($command->recipients) + count($command->cc) + count($command->bcc)
			> LicenseManager::getMessageRecipientsTotalLimit()
		)
		{
			$result->addError(new Error('Recipient limit exceeded.', self::ERROR_TOO_MANY_RECIPIENTS));
		}

		return $result;
	}

	private function containsHeaderControlCharacters(SendMessageCommand $command): bool
	{
		$values = [
			$command->sender,
			...$command->recipients,
			...$command->cc,
			...$command->bcc,
			$command->subject,
			$command->messageId,
			$command->inReplyTo ?? '',
		];
		foreach ($command->attachments as $attachment)
		{
			$values[] = (string)($attachment['ID'] ?? '');
			$values[] = (string)($attachment['NAME'] ?? '');
		}

		foreach ($values as $value)
		{
			if (preg_match('/[\x00-\x1F\x7F]/', (string)$value))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The mailbox builds the raw message out of HEADER alone, so To and Subject have to be there. Mail::send()
	 * passes them to bxmail() itself, and the same keys in HEADER would duplicate both header lines.
	 *
	 * @return array<string, mixed>
	 */
	private function buildOutgoingParams(SendMessageCommand $command, bool $withEnvelopeHeaders): array
	{
		$headers = [
			'From' => $command->sender,
			'Reply-To' => $command->sender,
			'Cc' => implode(', ', $command->cc),
			'Bcc' => implode(', ', $command->bcc),
			'Message-Id' => $command->messageId,
		];
		if ($command->inReplyTo !== null)
		{
			$headers['In-Reply-To'] = sprintf('<%s>', trim($command->inReplyTo, '<>'));
		}
		if ($withEnvelopeHeaders)
		{
			$headers['To'] = implode(', ', $command->recipients);
			$headers['Subject'] = $command->subject;
		}

		return [
			'CHARSET' => SITE_CHARSET,
			'CONTENT_TYPE' => 'html',
			'ATTACHMENT' => $command->attachments,
			'TO' => implode(', ', $command->recipients),
			'SUBJECT' => $command->subject,
			'BODY' => $command->body,
			'HEADER' => $headers,
		];
	}

	private function subscribeToLargeAttachmentMarkers(SendMessageCommand $command): ?int
	{
		if ($command->externalLinkIds === [])
		{
			return null;
		}

		$expectedMessageId = trim($command->messageId, '<>');

		return EventManager::getInstance()->addEventHandler(
			'mail',
			'onMailMessageNew',
			static function (Event $event) use ($expectedMessageId, $command): void
			{
				$message = (array)$event->getParameter('message');
				if ((string)($message['MSG_ID'] ?? '') !== $expectedMessageId)
				{
					return;
				}

				$result = LargeAttachmentService::registerMessageMarkers(
					(int)($message['ID'] ?? 0),
					$command->externalLinkIds,
				);
				if (!$result->isSuccess())
				{
					(new \Bitrix\Main\Diag\LoggerFactory())->createById(
						'mail.large_attachment',
						[],
						false,
					)?->error(
						'Could not register outgoing message markers: {error}',
						['error' => $result->getErrors()[0]->getMessage()],
					);
				}
			},
		);
	}
}
