<?php
namespace Bitrix\BIConnector\Configuration;

use Bitrix\Bitrix24;
use Bitrix\BIConnector\Configuration\Feature\ChartAnalysisFlag;
use Bitrix\BIConnector\Configuration\Feature\DemoReportsFlag;
use Bitrix\BIConnector\Configuration\Feature\TablePermissionsFlag;
use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Class Feature
 * Provides unified methods for check B24 tariff restrictions.
 *
 * @package Bitrix\BIConnector\Configuration
 */
final class Feature
{
	public const BI_BUILDER = 'bi_constructor';
	public const BI_BUILDER_RIGHTS = 'bi_constructor_rights';
	public const BI_EXTERNAL_ENTITIES = 'bi_constructor_external_entities';
	public const BI_BUILDER_EXPORT = 'bi_constructor_export';
	public const BI_BUILDER_EXTERNAL_SQL_DATASET = 'bi_constructor_external_sql_dataset';
	public const CHECK_PERMISSION_BY_GROUP_OPTION = 'check_permissions_by_group';
	public const BITRIXGPT_BI_CONSTRUCTOR = 'bitrixgpt_bi_constructor';
	public const BI_AI_AGENTS_SECTION = 'bi_ai_agents_section';
	public const BI_AI_AGENTS_CARDS = 'bi_ai_agents_cards';
	public const AGGREGATION_PUSHDOWN = 'aggregation_pushdown';
	public const BI_DEMO_REPORTS = 'bi_demo_reports';
	public const REST_AGGREGATION_PUSHDOWN = 'rest_aggregation_pushdown';
	public const MCP_BI_CONNECTION = 'mcp_bi_connection';
	public const BI_DATASET_LABEL_BACKFILL = 'bi_dataset_label_backfill';
	public const MOBILE_DASHBOARD_PINNING = 'mobile_dashboard_pinning_enabled';
	public const PORTAL_HEALTHCHECK = 'portal_healthcheck_enabled';
	public const DASHBOARD_FILTER_PRESETS = 'dashboard_filter_presets_enabled';

	public static function isBuilderEnabled(): bool
	{
		return self::isFeatureEnabled(self::BI_BUILDER);
	}

	public static function isBiBuilderRightsEnabled(): bool
	{
		return self::isBuilderEnabled() && self::isFeatureEnabled(self::BI_BUILDER_RIGHTS);
	}

	public static function isExternalEntitiesEnabled(): bool
	{
		return
			self::isBuilderEnabled()
			&& self::isFeatureEnabled(self::BI_EXTERNAL_ENTITIES)
		;
	}

	public static function isSourceExpensesEnabled(): bool
	{
		return self::isExternalEntitiesEnabled();
	}

	public static function isBiBuilderExportEnabled(): bool
	{
		return
			self::isBuilderEnabled()
			&& self::isFeatureEnabled(self::BI_BUILDER_EXPORT)
		;
	}

	public static function isExternalSqlDatasetEnabled(): bool
	{
		return
			self::isBuilderEnabled()
			&& !Loader::includeModule('bitrix24')
		;
	}

	public static function isClickhouseSourceEnabled(): bool
	{
		return self::isExternalSqlDatasetEnabled();
	}

	public static function isMobileDashboardPinningEnabled(): bool
	{
		return self::checkFeatureOption(self::MOBILE_DASHBOARD_PINNING);
	}

	public static function isPortalHealthcheckEnabled(): bool
	{
		return self::checkFeatureOption(self::PORTAL_HEALTHCHECK);
	}

	public static function isDashboardFilterPresetsEnabled(): bool
	{
		return self::checkFeatureOption(self::DASHBOARD_FILTER_PRESETS);
	}

	public static function isCheckPermissionsByGroup(): bool
	{
		return self::checkFeatureOption(self::CHECK_PERMISSION_BY_GROUP_OPTION);
	}

	/**
	 * The table permission axis: whether it is applied on this portal at all.
	 *
	 * The single answer to that question - the endpoint, the notifier and the rollout screens ask this
	 * and never assemble a condition of their own.
	 *
	 * The tariff flag alone cannot carry the axis: on box it is always on, and in cloud it is on for
	 * every tariff where dashboard group rights already work, so the axis would switch itself on the
	 * day the code arrives. Hence the second half, which stays off until the rollout turns it on - the
	 * rollout owns it, this side only reads it.
	 *
	 * The tariff half stays here, in the conjunction, and is deliberately not moved into the
	 * requirements of the flag. A flag class the core cannot resolve is reported as enabled - the
	 * opposite direction to the one an axis of rights needs - and requirements are consulted after
	 * that, so they never run in the very case they would have to cover. Kept here, the tariff half
	 * still holds when the flag half fails open, which bounds that failure to the tariffs where group
	 * rights already work instead of opening the axis everywhere.
	 *
	 * @return bool
	 */
	public static function isTablePermissionsEnabled(): bool
	{
		return
			self::isBiBuilderRightsEnabled()
			&& Main\Config\Feature::isEnabled(TablePermissionsFlag::class)
		;
	}

	/**
	 * The rollout half of the axis on its own, and never an answer to "is the axis on".
	 *
	 * One caller: the handler of a licence change, whose question is whether the tariff can move the
	 * answer of isTablePermissionsEnabled() at all. While the rollout is off it cannot - the conjunction
	 * reads false on both sides of such a change - and a portal that never rolled the axis out is then
	 * spared a forced reset and the retry agent standing behind it.
	 *
	 * @return bool
	 */
	public static function isTablePermissionsRolledOut(): bool
	{
		return Main\Config\Feature::isEnabled(TablePermissionsFlag::class);
	}

	public static function isChartAnalysisEnabled(): bool
	{
		return Main\Config\Feature::isEnabled(ChartAnalysisFlag::class);
	}

	public static function isBitrixGptBiConstructorEnabled(): bool
	{
		return self::checkFeatureOption(self::BITRIXGPT_BI_CONSTRUCTOR);
	}

	public static function isAiAgentsSectionEnabled(): bool
	{
		return self::checkFeatureOption(self::BI_AI_AGENTS_SECTION);
	}

	public static function isAiAgentsCardsEnabled(): bool
	{
		return self::checkFeatureOption(self::BI_AI_AGENTS_CARDS);
	}

	/**
	 * Turning the option off stops publishing capabilities and accepting aggregation blocks at once:
	 * the plugin then plans without pushdown, which degrades performance but never the numbers.
	 */
	public static function isAggregationPushdownEnabled(): bool
	{
		return self::isBuilderEnabled() && self::checkFeatureOption(self::AGGREGATION_PUSHDOWN);
	}

	/**
	 * The REST channel of the pushdown, shipped behind an option of its own on top of the common switch:
	 * turning either of them off returns the channel to the row path.
	 */
	public static function isRestAggregationPushdownEnabled(): bool
	{
		return
			self::isAggregationPushdownEnabled()
			&& self::checkFeatureOption(self::REST_AGGREGATION_PUSHDOWN)
		;
	}

	public static function isDemoReportsEnabled(): bool
	{
		return Main\Config\Feature::isEnabled(DemoReportsFlag::class);
	}

	public static function isMcpBiConnectionEnabled(): bool
	{
		return self::checkFeatureOption(self::MCP_BI_CONNECTION);
	}

	/**
	 * Labelling of the physical datasets that already exist on the instance requires a Superset that
	 * stores the dataset name, so the sweep stays off until the instance is updated.
	 */
	public static function isDatasetLabelBackfillEnabled(): bool
	{
		return self::checkFeatureOption(self::BI_DATASET_LABEL_BACKFILL);
	}

	public static function checkFeatureOption(string $optionName): bool
	{
		return Option::get('biconnector', $optionName, 'N') === 'Y';
	}

	/**
	 * @param string $featureId
	 * @return bool
	 *
	 * @throws Main\LoaderException
	 */
	private static function isFeatureEnabled(string $featureId): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Bitrix24\Feature::isFeatureEnabled($featureId);
		}

		return true;
	}
}
