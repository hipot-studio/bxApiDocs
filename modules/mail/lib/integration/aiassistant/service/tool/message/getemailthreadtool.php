<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageSearch;
use Bitrix\Main\SystemException;

class GetEmailThreadTool extends ToolContract
{
	public const ACTION_NAME = 'get_email_thread';

	public function __construct(
		private readonly MessageSearch $messageSearch,
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
			"Returns one page of the conversation branch a message belongs to: the messages it "
			. "replies to, the message itself, and the replies to it. Parallel branches are not "
			. "included, so this is not necessarily the whole conversation. "
			. "Each entry has subject, from, to, cc, date, and body (plain text, capped; "
			. "truncated=true means it was cut), sorted chronologically; withBodies=false "
			. "returns headers only. "
			. "Pages run from the newest end - keep paging with offset until hasMore=false before "
			. "summarizing the branch. "
			. "Requires the message identifier from the search_emails tool."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'messageId' => [
					'type' => 'integer',
					'description' => 'Identifier of the message the branch is assembled around.',
					'minimum' => 1,
				],
				'limit' => [
					'type' => 'integer',
					'description' => 'Number of messages per page. Defaults to '
						. MessageSearch::THREAD_PAGE_SIZE_DEFAULT . '; capped at '
						. MessageSearch::MAX_THREAD_PAGE_SIZE_WITH_BODIES . ' with bodies and at '
						. MessageSearch::MAX_THREAD_PAGE_SIZE_HEADERS . ' in headers mode.',
					'minimum' => 1,
					'maximum' => MessageSearch::MAX_THREAD_PAGE_SIZE_HEADERS,
				],
				'offset' => [
					'type' => 'integer',
					'description' => 'Number of messages to skip, counted from the newest. Defaults to 0.',
					'minimum' => 0,
				],
				'withBodies' => [
					'type' => 'boolean',
					'description' => 'true = include message bodies (capped); false = headers only (subject/from/to/date), cheaper for overview. Defaults to true.',
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
		$withBodies = isset($args['withBodies']) ? (bool)$args['withBodies'] : true;
		$limit = MessageSearch::clampThreadPageSize(
			isset($args['limit']) ? (int)$args['limit'] : MessageSearch::THREAD_PAGE_SIZE_DEFAULT,
			$withBodies,
		);
		$offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;

		if ($messageId <= 0)
		{
			throw new McpException('Parameter messageId is required and must be a positive integer.');
		}

		try
		{
			$result = $this->messageSearch->getMessageThread(
				messageId: $messageId,
				userId: $userId,
				limit: $limit,
				offset: $offset,
				withBodies: $withBodies,
			);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return $result;
	}
}
