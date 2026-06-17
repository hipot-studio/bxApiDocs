<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Timeline\Comment;

use Bitrix\Main\Validation\Rule\PositiveNumber;

final class CreateCommentDealTool extends BaseCommentTool
{
	#[PositiveNumber(errorMessage: 'Deal ID must be a positive integer')]
	public int $dealId;

	public function getName(): string
	{
		return 'create_comment_deal';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Adds a comment to the timeline of the specified deal.
			Use it to attach a text comment to a deal in CRM.
			It returns success or error message.
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dealId' => [
					'description' => 'CRM deal ID.',
					'type' => 'integer',
				],
				'comment' => [
					'description' => 'Comment text to add to the deal timeline.',
					'type' => 'string',
					'minLength' => 1,
				],
			],
			'additionalProperties' => false,
			'required' => ['dealId', 'comment'],
		];
	}

	protected function innerExecute(): string
	{
		return $this->createComment(\CCrmOwnerType::Deal, $this->dealId);
	}

	protected function parseInput(array $args): void
	{
		$this->dealId = (int)($args['dealId'] ?? 0);

		parent::parseInput($args);
	}
}
