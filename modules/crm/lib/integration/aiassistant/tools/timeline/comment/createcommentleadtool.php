<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Timeline\Comment;

use Bitrix\Main\Validation\Rule\PositiveNumber;

final class CreateCommentLeadTool extends BaseCommentTool
{
	#[PositiveNumber(errorMessage: 'Lead ID must be a positive integer')]
	public int $leadId;

	public function getName(): string
	{
		return 'create_comment_lead';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Adds a comment to the timeline of the specified lead.
			Use it to attach a text comment to a lead in CRM.
			It returns success or error message.
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'leadId' => [
					'description' => 'CRM lead ID.',
					'type' => 'integer',
				],
				'comment' => [
					'description' => 'Comment text to add to the lead timeline.',
					'type' => 'string',
					'minLength' => 1,
				],
			],
			'additionalProperties' => false,
			'required' => ['leadId', 'comment'],
		];
	}

	protected function innerExecute(): string
	{
		return $this->createComment(\CCrmOwnerType::Lead, $this->leadId);
	}

	protected function parseInput(array $args): void
	{
		$this->leadId = (int)($args['leadId'] ?? 0);

		parent::parseInput($args);
	}
}
