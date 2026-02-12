<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

use Bitrix\Crm\Integration\AiAssistant\Helper\CultureHelper;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Filter\DateType;
use CCrmOwnerType;

class DealListTool extends BaseListTool
{
	public function getName(): string
	{
		return 'deal_list';
	}

	public function getDescription(): string
	{
		return <<<TEXT
Searches for deals by parameters. 
Use this function when you need to find deals by keyword or other criteria.
Filter by stage, stage semantic (in progress, success, failed), category (funnel) or find deals closed in a specific period.
A limit on the number of deals to search can also be specified.
For the found deals, a special URL [`items_url`] is generated that opens the CRM deal list.
TEXT;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'keyword' => [
					'description' => 'Keyword to search for a deal (minimum 2 characters).',
					'type' => 'string',
					'minLength' => 2,
					'maxLength' => 50,
				],
				'categoryId' => [
					'description' => 'Deal category (funnel) identifier. If not specified, searches across all categories.',
					'type' => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				],
				'stageId' => [
					'description' => 'Deal stage identifier. If not specified, searches across all stages.',
					'type' => 'string',
				],
				'stageSemantic' => [
					'description' => 'Deal stage semantics: "P" for deals in progress, "S" for successful/won deals, "F" for failed/lost deals.',
					'type' => 'string',
					'enum' => PhaseSemantics::ALLOWED_SEMANTICS,
					'default' => PhaseSemantics::PROCESS,
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
				'limit' => [
					'description' => 'Maximum number of deals to return.',
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
		return CCrmOwnerType::Deal;
	}

	final protected function buildFilter(int $userId, array $args): array
	{
		$ormFilter = [];

		$dateFormat = $this->cultureHelper->getDateFormat();
		$categoryId = $this->argumentExtractor->extractCategoryId($args);
		if (isset($categoryId))
		{
			$ormFilter['=CATEGORY_ID'] = $categoryId;
			$this->filterParams['CATEGORY_ID'] = $categoryId;
		}

		$stageId = $this->argumentExtractor->extractString($args, 'stageId');
		if (
			$this
				->permissionService
				->isStageAvailable($stageId, $userId, $this->getEntityTypeId(), $categoryId)
		)
		{
			$ormFilter['=STAGE_ID'] = $stageId;
			$this->filterParams['STAGE_ID'] = $stageId;
		}

		$stageSemantic = $this->argumentExtractor->extractString($args, 'stageSemantic');
		if (in_array($stageSemantic, PhaseSemantics::ALLOWED_SEMANTICS, true))
		{
			$ormFilter['=STAGE_SEMANTIC_ID'] = $stageSemantic;
			$this->filterParams['STAGE_SEMANTIC_ID'] = $stageSemantic;
		}

		$closedFromStr = $this->argumentExtractor->extractString($args, 'closedDateFrom');
		$closedToStr = $this->argumentExtractor->extractString($args, 'closedDateTo');
		if ($closedFromStr !== '' || $closedToStr !== '')
		{
			$this->filterParams['CLOSEDATE_datesel'] = DateType::RANGE;

			$closedFrom = DateTime::tryParse($closedFromStr, CultureHelper::DEFAULT_DATE_FORMAT);
			if ($closedFrom)
			{
				$ormFilter['>=CLOSEDATE'] = $closedFrom;
				$this->filterParams['CLOSEDATE_from'] = $closedFrom->format($dateFormat);
			}

			$closedTo = DateTime::tryParse($closedToStr, CultureHelper::DEFAULT_DATE_FORMAT);
			if ($closedTo)
			{
				$ormFilter['<=CLOSEDATE'] = (clone $closedTo)->setTime(23, 59, 59);
				$this->filterParams['CLOSEDATE_to'] = $closedTo->format($dateFormat);
			}
		}

		return $ormFilter;
	}
}
