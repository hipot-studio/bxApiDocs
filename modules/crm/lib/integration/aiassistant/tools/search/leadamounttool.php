<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

use Bitrix\Crm\PhaseSemantics;

final class LeadAmountTool extends LeadListTool
{
	public function getName(): string
	{
		return 'lead_amount';
	}

	public function getDescription(): string
	{
		return <<<TEXT
Calculating amounts for leads by parameters.
Use this function when you need to calculate amounts for leads.
Filter by stage semantic (in progress, success, failed) or find leads closed in a specific period.
TEXT;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'stageId' => [
					'description' => 'Lead stage identifier. If not specified, searches across all stages.',
					'type' => 'string',
				],
				'stageSemantic' => [
					'description' => 'Filter by lead stage semantics: "P" for leads in progress, "S" for successful/converted leads, "F" for failed/rejected leads.',
					'type' => 'string',
					'enum' => PhaseSemantics::ALLOWED_SEMANTICS,
				],
				'closedDateFrom' => [
					'description' => 'Start date for filtering closed leads (format: YYYY-MM-DD).',
					'type' => 'string',
					'format' => 'date',
				],
				'closedDateTo' => [
					'description' => 'End date for filtering closed leads (format: YYYY-MM-DD).',
					'type' => 'string',
					'format' => 'date',
				],
			],
			'additionalProperties' => false,
			'required' => [
				'stageId',
				'stageSemantic',
			],
		];
	}

	public function executeTool(int $userId, ...$args): string
	{
		$stageId = $this->argumentExtractor->extractString($args, 'stageId');
		$amount = $this->metadataService->getAmount(
			$this->getEntityTypeId(),
			$userId,
			$stageId,
			$this->buildFilter($userId, $args)
		);

		return isset($amount)
			? $this->responseFormatter->formatAmountResponse($amount)
			: 'Unable to determine the amount based on the specified parameters'
		;
	}
}
