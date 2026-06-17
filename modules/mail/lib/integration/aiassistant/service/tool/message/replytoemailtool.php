<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageSender;
use Bitrix\Mail\Helper\RecipientHelper;
use Bitrix\Main\SystemException;

class ReplyToEmailTool extends ToolContract
{
	public const ACTION_NAME = 'reply_to_email';

	public function __construct(
		private readonly RecipientHelper $recipientHelper,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return self::ACTION_NAME;
	}

	public function getDescription(): string
	{
		return
			"Replies to an existing email message on behalf of the user. "
			. "Sets the In-Reply-To header so the reply threads correctly in the recipient's mail client, "
			. "and appends the quoted original body. Includes only inline attachments from the source. "
			. "Requires replyToMessageId of the source message, sender email, recipients, subject, and body. "
			. "Recipients in to, cc, and bcc must be email addresses; resolve any names via list_mail_recipients or search_employee_emails first. "
			. "The total number of recipients across to, cc, and bcc must not exceed 10."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'replyToMessageId' => [
					'type' => 'integer',
					'description' => 'Identifier of the source message to reply to. Sets In-Reply-To header and appends quoted original body.',
					'minimum' => 1,
				],
				'from' => [
					'type' => 'string',
					'description' => 'Sender email address. Must be one of the user\'s available mailbox senders.',
					'minLength' => 1,
				],
				'to' => [
					'type' => 'array',
					'description' => 'List of recipient email addresses (typically the original sender for a simple reply).',
					'items' => [
						'type' => 'string',
					],
					'minItems' => 1,
					'maxItems' => 10,
				],
				'subject' => [
					'type' => 'string',
					'description' => 'Email subject line (typically prefixed with "Re:").',
					'minLength' => 1,
				],
				'body' => [
					'type' => 'string',
					'description' => 'Reply text shown above the quoted original. Plain text or basic HTML.',
					'minLength' => 1,
				],
				'cc' => [
					'type' => 'array',
					'description' => 'List of CC recipient email addresses (used for reply-all).',
					'items' => [
						'type' => 'string',
					],
					'maxItems' => 10,
				],
				'bcc' => [
					'type' => 'array',
					'description' => 'List of BCC recipient email addresses.',
					'items' => [
						'type' => 'string',
					],
					'maxItems' => 10,
				],
			],
			'required' => ['replyToMessageId', 'from', 'to', 'subject', 'body'],
			'additionalProperties' => false,
		];
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$replyToMessageId = (int)($args['replyToMessageId'] ?? 0);
		$from = (string)($args['from'] ?? '');
		$recipients = (array)($args['to'] ?? []);
		$subject = (string)($args['subject'] ?? '');
		$body = (string)($args['body'] ?? '');
		$cc = (array)($args['cc'] ?? []);
		$bcc = (array)($args['bcc'] ?? []);

		try
		{
			$recipients = $this->recipientHelper->resolveRecipients($recipients, $userId);
			$cc = !empty($cc) ? $this->recipientHelper->resolveRecipients($cc, $userId) : [];
			$bcc = !empty($bcc) ? $this->recipientHelper->resolveRecipients($bcc, $userId) : [];
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		try
		{
			return (new MessageSender())->reply(
				$replyToMessageId, $from, $recipients, $subject, $body, $userId, $cc, $bcc,
			);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}
	}
}
