<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

use Bitrix\Crm\PhaseSemantics;

final class DealAmountTool extends DealListTool
{
	public function getName(): string
	{
		return 'deal_amount';
	}

	public function getDescription(): string
	{
		return <<<TEXT
Calculating amounts for deals by parameters. 
Use this function when you need to calculate amounts for deals.
Filter by stage semantic (in progress, success, failed), category (funnel) or find deals closed in a specific period.
TEXT;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'categoryId' => [
					'description' => 'Deal category (funnel) identifier. If not specified, searches across all categories.',
					'type' => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
					'default' => 0,
				],
				'stageId' => [
					'description' => 'Deal stage identifier. If not specified, searches across all stages.',
					'type' => 'string',
				],
				'stageSemantic' => [
					'description' => 'Filter by deal stage semantics: "P" for deals in progress, "S" for successful/won deals, "F" for failed/lost deals.',
					'type' => 'string',
					'enum' => PhaseSemantics::ALLOWED_SEMANTICS,
				],
				'closedDateFrom' => [
					'description' => 'Start date for filtering closed deals (format: YYYY-MM-DD).',
					'type' => 'string',
					'format' => 'date',
				],
				'closedDateTo' => [
					'description' => 'End date for filtering closed deals (format: YYYY-MM-DD).',
					'type' => 'string',
					'format' => 'date',
				],
			],
			'additionalProperties' => false,
			'required' => [
				'categoryId',
				'stageId',
				'stageSemantic',
			],
		];
	}

	public function executeTool(int $userId, ...$args): string
	{
		$categoryId = $this->argumentExtractor->extractCategoryId($args);
		$stageId = $this->argumentExtractor->extractString($args, 'stageId');
		$amount = $this->metadataService->getAmount(
			$this->getEntityTypeId(),
			$userId,
			$stageId,
			$this->buildFilter($userId, $args),
			$categoryId
		);

		return isset($amount)
			? $this->responseFormatter->formatAmountResponse($amount)
			: 'Unable to determine the amount based on the specified parameters'
		;
	}
}
