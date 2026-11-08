<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\ChartAnalysis;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

/**
 * Builds the opening message of a chart analysis chat: its title, the chart card the user sees and the page
 * context the model reads.
 */
final class ChartAnalysisMessageBuilder
{
	/**
	 * im drops a COPILOT_PAGE_CONTEXT whose encoding reaches this length (CopilotPageContext::MAX_JSON_LENGTH,
	 * private there), so a context that does not fit is refused before anything is written.
	 */
	public const MAX_PAGE_CONTEXT_BYTES = 60000;

	public const SCENARIO = 'chart_analysis';

	public const MAX_DASHBOARD_DESCRIPTION_LENGTH = 500;

	private const FILTER_SEPARATOR = '; ';

	private const ELLIPSIS = '…';

	public function buildTitle(ChartAnalysisPayload $payload): string
	{
		return (string)Loc::getMessage(
			'BICONNECTOR_CHART_ANALYSIS_TITLE',
			['#NAME#' => $this->neutralizeBbCode($payload->sliceName)],
		);
	}

	/**
	 * The card of the chart as of the click: name, period and filters (AC-016).
	 */
	public function buildText(ChartAnalysisPayload $payload): string
	{
		$lines = [
			(string)Loc::getMessage(
				'BICONNECTOR_CHART_ANALYSIS_TEXT_CHART',
				['#NAME#' => $this->neutralizeBbCode($payload->sliceName)],
			),
		];

		if ($payload->periodLabel !== null)
		{
			$lines[] = (string)Loc::getMessage(
				'BICONNECTOR_CHART_ANALYSIS_TEXT_PERIOD',
				['#PERIOD#' => $this->neutralizeBbCode($payload->periodLabel)],
			);
		}

		if ($payload->filters !== [])
		{
			$filters = array_map(
				fn(array $filter): string => (string)Loc::getMessage(
					'BICONNECTOR_CHART_ANALYSIS_TEXT_FILTER',
					[
						'#LABEL#' => $this->neutralizeBbCode($filter['label']),
						'#VALUE#' => $this->neutralizeBbCode($filter['value']),
					],
				),
				$payload->filters,
			);

			$lines[] = (string)Loc::getMessage(
				'BICONNECTOR_CHART_ANALYSIS_TEXT_FILTERS',
				['#FILTERS#' => implode(self::FILTER_SEPARATOR, $filters)],
			);
		}

		return implode("\n", $lines);
	}

	/**
	 * The ids are the ones the tools take: the Superset id of the chart and the Bitrix24 id of the report. The
	 * flag of cross-filters tells the model that the tools do not apply them; the filters themselves are already
	 * in the card text.
	 *
	 * @param int $dashboardId Id of the report in Bitrix24, not in Superset.
	 *
	 * @return array{
	 *     system: array{module: string, url: string, scenario: string},
	 *     custom: array{
	 *         instructions: string,
	 *         sliceId: int,
	 *         dashboardId: int,
	 *         hasCrossFilters?: true,
	 *         data: array,
	 *         dashboardDescription?: string,
	 *     },
	 * }
	 */
	public function buildPageContext(
		ChartAnalysisPayload $payload,
		int $dashboardId,
		string $url,
		?string $dashboardDescription = null,
	): array
	{
		$custom = [
			'instructions' => (string)Loc::getMessage('BICONNECTOR_CHART_ANALYSIS_INSTRUCTIONS'),
			'sliceId' => $payload->sliceId,
			'dashboardId' => $dashboardId,
		];

		foreach ($payload->filters as $filter)
		{
			if (($filter['source'] ?? null) === ChartAnalysisPayload::FILTER_SOURCE_CROSS)
			{
				$custom['hasCrossFilters'] = true;
				break;
			}
		}

		$custom['data'] = $payload->data;

		$dashboardDescription = trim((string)$dashboardDescription);
		if ($dashboardDescription !== '')
		{
			$custom['dashboardDescription'] = $this->truncate(
				$dashboardDescription,
				self::MAX_DASHBOARD_DESCRIPTION_LENGTH,
			);
		}

		return [
			'system' => [
				'module' => 'biconnector',
				'url' => $url,
				'scenario' => self::SCENARIO,
			],
			'custom' => $custom,
		];
	}

	/**
	 * Measured the way im measures it: the length in bytes of the same encoding.
	 */
	public function fits(array $pageContext): bool
	{
		return strlen(Json::encode($pageContext)) < self::MAX_PAGE_CONTEXT_BYTES;
	}

	/**
	 * The message text and the chat title are BB-code channels, while the card strings come from the author
	 * of the chart and from filter values. Brackets are replaced the way im does it for file names
	 * (AbstractBlock::neutralizeBbCode), not entity-encoded: Text::parse() decodes &#91; back to '['.
	 */
	private function neutralizeBbCode(string $value): string
	{
		return str_replace(['[', ']'], ['(', ')'], $value);
	}

	private function truncate(string $value, int $maxLength): string
	{
		if (mb_strlen($value) <= $maxLength)
		{
			return $value;
		}

		return mb_substr($value, 0, $maxLength - 1) . self::ELLIPSIS;
	}
}
