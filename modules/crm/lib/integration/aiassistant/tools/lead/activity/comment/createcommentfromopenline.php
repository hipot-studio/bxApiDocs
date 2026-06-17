<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Lead\Activity\Comment;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Integration\AiAssistant\Helper\OpenLineService;
use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Result;
use CCrmOwnerType;

final class CreateCommentFromOpenLine extends BaseCrmTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly OpenLineService $openLineService,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return 'create_lead_comment_from_openline';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Allows you to create a comment in a CRM lead.
			This tool is intended for external Open Channel users.
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'leadId' => [
					'description' => 'The lead ID for which the comment is being created.',
					'type' => 'integer',
				],
				'comment' => [
					'description' => 'Comment text.',
					'type' => 'string',
					'minLength' => 1,
				],
			],
			'additionalProperties' => false,
			'required' => [
				'leadId',
				'comment',
			],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$args = new CreateCommentDto($args);
		if ($args->hasValidationErrors())
		{
			$result = Result::fail($args->getValidationErrors());

			return self::fail($result);
		}

		$result = $this->openLineService->createComment(
			userId: $userId,
			entityTypeId: CCrmOwnerType::Lead,
			entityId: $args->leadId,
			comment: $args->comment,
		);

		if (!$result->isSuccess())
		{
			return self::fail($result);
		}

		return self::success();
	}

	protected static function fail(Result $result): string
	{
		return 'Failed to create a comment for the lead: ' . implode(',', $result->getErrorMessages());
	}

	protected static function success(): string
	{
		return 'A comment was successfully added to the lead.';
	}
}
