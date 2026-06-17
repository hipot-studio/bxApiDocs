<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageActions;
use Bitrix\Main\SystemException;

class CreateFeedPostFromEmailTool extends ToolContract
{
	public const ACTION_NAME = 'create_feed_post_from_email';

	public function __construct(
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
			"Creates a feed post (blog post) from a mail message and links it to the message. "
			. "The post title defaults to the email subject. "
			. "Requires the message identifier obtained from the search_emails tool."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'messageId' => [
					'type' => 'integer',
					'description' => 'Identifier of the mail message to create feed post from.',
					'minimum' => 1,
				],
				'title' => [
					'type' => ['string', 'null'],
					'description' => 'Post title. Defaults to the email subject if not specified.',
				],
			],
			'required' => ['messageId'],
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
		$messageId = (int)($args['messageId'] ?? 0);

		if ($messageId <= 0)
		{
			throw new McpException('Parameter messageId is required and must be a positive integer.');
		}

		try
		{
			$result = MessageActions::createFeedPost(
				messageId: $messageId,
				userId: $userId,
				title: $args['title'] ?? null,
			);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new McpException($result->getErrors()[0]->getMessage());
		}

		$data = $result->getData();

		return [
			'success' => true,
			'postId' => $data['postId'],
			'messageId' => $messageId,
		];
	}
}
