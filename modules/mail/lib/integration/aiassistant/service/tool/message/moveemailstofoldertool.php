<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageActions;
use Bitrix\Main\SystemException;

class MoveEmailsToFolderTool extends ToolContract
{
	public const ACTION_NAME = 'move_emails_to_folder';

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
			"Moves email messages to a specified folder, spam, or trash. "
			. "Use action 'move' for regular folder move, 'spam' for marking as spam, "
			. "'delete' for moving to trash. "
			. "Requires message identifiers obtained from the search_emails tool."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'messageIds' => [
					'type' => 'array',
					'items' => [
						'type' => 'integer',
						'minimum' => 1,
					],
					'description' => 'Array of message identifiers to move. Maximum 100 per call; split into multiple calls for larger batches.',
					'minItems' => 1,
					'maxItems' => 100,
				],
				'action' => [
					'type' => 'string',
					'enum' => ['move', 'spam', 'delete'],
					'description' => "Action to perform: 'move' to folder, 'spam' to mark as spam, 'delete' to move to trash.",
				],
				'folder' => [
					'type' => 'string',
					'description' => "Target folder name (e.g., INBOX, Sent). Required when action is 'move', omit otherwise.",
				],
			],
			'required' => ['messageIds', 'action'],
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
		$messageIds = $args['messageIds'] ?? [];
		$action = $args['action'] ?? '';
		$folder = $args['folder'] ?? null;

		if (empty($messageIds))
		{
			throw new McpException('Parameter messageIds is required and must not be empty.');
		}

		if ($action === 'move' && empty($folder))
		{
			throw new McpException("Parameter folder is required when action is 'move'.");
		}

		try
		{
			$result = match ($action)
			{
				'move' => MessageActions::moveToFolderByMessageIds($messageIds, $folder, $userId),
				'spam' => MessageActions::markAsSpamByMessageIds($messageIds, $userId),
				'delete' => MessageActions::deleteByMessageIds($messageIds, $userId),
				default => throw new McpException("Unknown action: {$action}"),
			};
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
			'movedCount' => (int)($data['affectedCount'] ?? count($messageIds)),
			'action' => $action,
		];
	}
}
