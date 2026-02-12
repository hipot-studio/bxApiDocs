<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

use Bitrix\Crm\Integration\AiAssistant\Helper\CultureHelper;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Filter\DateType;
use CCrmOwnerType;

class LeadListTool extends BaseListTool
{
	public function getName(): string
	{
		return 'lead_list';
	}

	public function getDescription(): string
	{
		return <<<TEXT
Searches for leads by parameters.
Use this function when you need to find leads by keyword or other criteria.
Filter by stage, stage semantic (in progress, success, failed) or find leads closed in a specific period.
A limit on the number of leads to search can also be specified.
For the found leads, a special URL [`items_url`] is generated that opens the CRM lead list.
TEXT;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'keyword' => [
					'description' => 'Keyword to search for a lead (minimum 2 characters).',
					'type' => 'string',
					'minLength' => 2,
					'maxLength' => 50,
				],
				'stageId' => [
					'description' => 'Lead stage identifier. If not specified, searches across all stages.',
					'type' => 'string',
				],
				'stageSemantic' => [
					'description' => 'Lead stage semantics: "P" for leads in progress, "S" for successful/converted leads, "F" for failed/rejected leads.',
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
				'limit' => [
					'description' => 'Maximum number of leads to return.',
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::DEFAULT_ITEMS_MAX_LIMIT,
					'default' => self::DEFAULT_ITEMS_LIMIT,
				],
			],
			'additionalProperties' => false,
		];
	}

	final protected function getEntityTypeId(): int
	{
		return CCrmOwnerType::Lead;
	}

	final protected function buildFilter(int $userId, array $args): array
	{
		$ormFilter = [];

		$dateFormat = $this->cultureHelper->getDateFormat();
		$stageId = $this->argumentExtractor->extractString($args, 'stageId');
		if (
			$this
				->permissionService
				->isStageAvailable($stageId, $userId, $this->getEntityTypeId())
		)
		{
			$ormFilter['=STATUS_ID'] = $stageId;
			$this->filterParams['STATUS_ID'] = $stageId;
		}

		$stageSemantic = $this->argumentExtractor->extractString($args, 'stageSemantic');
		if (in_array($stageSemantic, PhaseSemantics::ALLOWED_SEMANTICS, true))
		{
			$ormFilter['=STATUS_SEMANTIC_ID'] = $stageSemantic;
			$this->filterParams['STATUS_SEMANTIC_ID'] = $stageSemantic;
		}

		$closedFromStr = $this->argumentExtractor->extractString($args, 'closedDateFrom');
		$closedToStr = $this->argumentExtractor->extractString($args, 'closedDateTo');
		if ($closedFromStr !== '' || $closedToStr !== '')
		{
			$this->filterParams['DATE_CLOSED_datesel'] = DateType::RANGE;

			$closedFrom = DateTime::tryParse($closedFromStr, CultureHelper::DEFAULT_DATE_FORMAT);
			if ($closedFrom)
			{
				$ormFilter['>=DATE_CLOSED'] = $closedFrom;
				$this->filterParams['DATE_CLOSED_from'] = $closedFrom->format($dateFormat);
			}

			$closedTo = DateTime::tryParse($closedToStr, CultureHelper::DEFAULT_DATE_FORMAT);
			if ($closedTo)
			{
				$ormFilter['<=DATE_CLOSED'] = (clone $closedTo)->setTime(23, 59, 59);
				$this->filterParams['DATE_CLOSED_to'] = $closedTo->format($dateFormat);
			}
		}

		return $ormFilter;
	}
}
