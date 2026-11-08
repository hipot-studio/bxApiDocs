<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Fixed catalog of AI agents launch scenarios shown as cards on the section page.
 *
 * The set of scenarios is identical for every tariff and every availability state:
 * neither the report nor the entry source parametrizes it.
 */
final class AiAgentsScenarioCatalog
{
	public const GROUP_BUILD = 'build';
	public const GROUP_ANALYZE = 'analyze';

	private const SCENARIOS = [
		['id' => 'prepare_report', 'group' => self::GROUP_BUILD, 'code' => 'PREPARE_REPORT'],
		['id' => 'configure_dashboard', 'group' => self::GROUP_BUILD, 'code' => 'CONFIGURE_DASHBOARD'],
		['id' => 'work_with_datasets', 'group' => self::GROUP_BUILD, 'code' => 'WORK_WITH_DATASETS'],
		['id' => 'describe_metrics', 'group' => self::GROUP_BUILD, 'code' => 'DESCRIBE_METRICS'],
		['id' => 'find_anomalies', 'group' => self::GROUP_ANALYZE, 'code' => 'FIND_ANOMALIES'],
		['id' => 'explain_trends', 'group' => self::GROUP_ANALYZE, 'code' => 'EXPLAIN_TRENDS'],
		['id' => 'prepare_insights', 'group' => self::GROUP_ANALYZE, 'code' => 'PREPARE_INSIGHTS'],
		['id' => 'test_hypotheses', 'group' => self::GROUP_ANALYZE, 'code' => 'TEST_HYPOTHESES'],
	];

	/**
	 * @return array<int, array{id: string, group: string, title: string, description: string, prompt: string}>
	 */
	public static function getCards(): array
	{
		return array_map(
			static fn(array $scenario): array => [
				'id' => $scenario['id'],
				'group' => $scenario['group'],
				'title' => (string)Loc::getMessage('BICONNECTOR_AI_AGENTS_' . $scenario['code'] . '_TITLE'),
				'description' => (string)Loc::getMessage('BICONNECTOR_AI_AGENTS_' . $scenario['code'] . '_DESCRIPTION'),
				'prompt' => (string)Loc::getMessage('BICONNECTOR_AI_AGENTS_' . $scenario['code'] . '_PROMPT'),
			],
			self::SCENARIOS,
		);
	}
}
