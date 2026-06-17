<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageSender;
use Bitrix\Mail\Helper\RecipientHelper;
use Bitrix\Main\SystemException;

class SendEmailTool extends ToolContract
{
	public const ACTION_NAME = 'send_email';

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
			"Sends a new email message on behalf of the user. "
			. "Requires subject, body, at least one recipient, and a sender email from the user's available mailbox senders. "
			. "Recipients in to, cc, and bcc must be email addresses; resolve any names via list_mail_recipients or search_employee_emails first. "
			. "The total number of recipients across to, cc, and bcc must not exceed 10. "
			. "Use forward_email to forward an existing message and reply_to_email to reply to one."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'from' => [
					'type' => 'string',
					'description' => 'Sender email address. Must be one of the user\'s available mailbox senders.',
					'minLength' => 1,
				],
				'to' => [
					'type' => 'array',
					'description' => 'List of recipient email addresses.',
					'items' => [
						'type' => 'string',
					],
					'minItems' => 1,
					'maxItems' => 10,
				],
				'subject' => [
					'type' => 'string',
					'description' => 'Email subject line.',
					'minLength' => 1,
				],
				'body' => [
					'type' => 'string',
					'description' => 'Email body content. Plain text or basic HTML.',
					'minLength' => 1,
				],
				'cc' => [
					'type' => 'array',
					'description' => 'List of CC recipient email addresses.',
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
			'required' => ['from', 'to', 'subject', 'body'],
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
			return (new MessageSender())->send($from, $recipients, $subject, $body, $userId, $cc, $bcc);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}
	}
}
