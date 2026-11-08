<?php

namespace Bitrix\BIConnector\Analytics;

use Bitrix\BIConnector\Internal\ChartAnalysis\ChartAnalysisPayload;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\AiAgentsScenarioLauncher;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\IssueResult;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\RefusalMap;
use Bitrix\BIConnector\Public\Command\ChartAnalysis\ChartAnalysisLaunchResult;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\Main\Analytics\AnalyticsEvent;

final class AnalyticsManager
{
	private const TOOL = 'BI_Builder';
	private const PERMISSIONS_CATEGORY = 'permissions';

	public const GROUP_GRID_SECTION = 'grid';
	public const GROUP_PERMISSION_SECTION = 'permissions_slider';

	private const PERMISSIONS_EDIT_ACTION = 'permissions_edited';

	public const MCP_CONNECTION_ISSUE = 'issue';
	public const MCP_CONNECTION_REISSUE = 'reissue';

	/**
	 * Answers for a code no table knows. Refusals of the core request filters reach the collection
	 * too - invalid_authentication, invalid_csrf, invalid_http_method - and the channel refusal
	 * carries no code at all, while a value the core does not accept would lose the whole event.
	 */
	public const SLUG_OTHER = 'other';

	/**
	 * The wording of the funnel lives in these three constants alone: neither the slug tables nor
	 * the point of collection depend on them.
	 */
	private const MCP_CONNECTION_CATEGORY = 'ai_agents';
	private const MCP_CONNECTION_SECTION = 'report_header';
	private const MCP_CONNECTION_EVENTS = [
		self::MCP_CONNECTION_ISSUE => 'mcp_connection_issued',
		self::MCP_CONNECTION_REISSUE => 'mcp_connection_reissued',
	];

	private const AI_AGENTS_SCENARIO_CATEGORY = 'ai_agents';
	private const AI_AGENTS_SCENARIO_SECTION = 'ai_agents_section';
	private const AI_AGENTS_SCENARIO_LAUNCHED = 'scenario_launched';
	private const AI_AGENTS_SCENARIO_REFUSED = 'scenario_refused';

	private const AI_AGENTS_SCENARIO_REFUSAL_SLUGS = [
		AiAgentsScenarioLauncher::REFUSAL_SECTION_CLOSED,
		AiAgentsScenarioLauncher::REFUSAL_CARD_NOT_FOUND,
		AiAgentsScenarioLauncher::REFUSAL_TARIFF,
		AiAgentsScenarioLauncher::REFUSAL_UNAVAILABLE,
		AiAgentsScenarioLauncher::REFUSAL_PREPARING,
		AiAgentsScenarioLauncher::REFUSAL_BOT_NOT_REGISTERED,
		AiAgentsScenarioLauncher::REFUSAL_CHAT_NOT_CREATED,
		AiAgentsScenarioLauncher::REFUSAL_MESSAGE_NOT_WRITTEN,
	];

	private const CHART_ANALYSIS_CATEGORY = 'ai_agents';
	private const CHART_ANALYSIS_SECTION = 'chart_analysis';
	private const CHART_ANALYSIS_LAUNCHED = 'chart_analysis_launched';
	private const CHART_ANALYSIS_REFUSED = 'chart_analysis_refused';
	private const CHART_ANALYSIS_PREVIOUS_PREFIX = 'previous_';
	private const CHART_ANALYSIS_CHAT_NEW = 'chat_new';
	private const CHART_ANALYSIS_CHAT_CONTINUED = 'chat_continued';
	private const CHART_ANALYSIS_VIZ_TYPE_PATTERN = '/^[a-z0-9_]{1,50}$/';

	/**
	 * The branches of the adapter carry their own slugs in {@see RefusalMap}; only the codes of the
	 * access gate, which refuses before any branch, are named here.
	 */
	private const ACCESS_GATE_SLUGS = [
		BIConstructorAccess::ERROR_INSUFFICIENT_TARIFF => 'tariff',
		BIConstructorAccess::ERROR_TOOL_DISABLED => 'tooloff',
		BIConstructorAccess::ERROR_ACCESS_DENIED => 'noaccess',
	];

	private const OUTCOME_SLUGS = [
		IssueResult::OUTCOME_ISSUED => 'issued',
		IssueResult::OUTCOME_EXISTS => 'exists',
	];

	static function sendSavePermissionsAnalytics(string $source): void
	{
		$event = new AnalyticsEvent(
			self::PERMISSIONS_EDIT_ACTION,
			self::TOOL,
			self::PERMISSIONS_CATEGORY
		);

		$event
			->setSubSection($source)
			->send()
		;
	}

	/**
	 * The event carries the outcome and nothing else: no secret, no address, no expiry date. Reading
	 * the state raises no event, so only the issue and the reissue reach here.
	 *
	 * @param string $action One of the MCP_CONNECTION_* action names.
	 * @param string $slug Outcome of the run, already resolved by one of the methods below.
	 */
	public static function sendMcpConnectionAnalytics(string $action, string $slug, bool $isSuccess): void
	{
		$eventName = self::MCP_CONNECTION_EVENTS[$action] ?? null;
		if ($eventName === null)
		{
			return;
		}

		$event = new AnalyticsEvent($eventName, self::TOOL, self::MCP_CONNECTION_CATEGORY);
		$event
			->setSection(self::MCP_CONNECTION_SECTION)
			->setP1($slug)
			->setStatus($isSuccess ? AnalyticsEvent::STATUS_SUCCESS : AnalyticsEvent::STATUS_ERROR)
			->send()
		;
	}

	/**
	 * @return string Slug of a refusal: a branch of the adapter, a code of the access gate, or the
	 * default for anything else. An unknown code never travels into the parameter as it came.
	 */
	public static function resolveMcpConnectionRefusalSlug(string $errorCode): string
	{
		return (new RefusalMap())->getAnalyticsSlug($errorCode)
			?? self::ACCESS_GATE_SLUGS[$errorCode]
			?? self::SLUG_OTHER
		;
	}

	/**
	 * @return string Slug of a successful run: the connection was issued or was already alive.
	 */
	public static function resolveMcpConnectionOutcomeSlug(string $outcome): string
	{
		return self::OUTCOME_SLUGS[$outcome] ?? self::SLUG_OTHER;
	}

	/**
	 * @param string $cardId Matched catalog ID, or a validated request ID before catalog lookup.
	 * @param string $slug Resolved refusal slug; empty on success.
	 */
	public static function sendAiAgentsScenarioAnalytics(string $cardId, string $slug, bool $isSuccess): void
	{
		$event = new AnalyticsEvent(
			$isSuccess ? self::AI_AGENTS_SCENARIO_LAUNCHED : self::AI_AGENTS_SCENARIO_REFUSED,
			self::TOOL,
			self::AI_AGENTS_SCENARIO_CATEGORY,
		);

		$event
			->setSection(self::AI_AGENTS_SCENARIO_SECTION)
			->setStatus($isSuccess ? AnalyticsEvent::STATUS_SUCCESS : AnalyticsEvent::STATUS_ERROR)
		;

		// p1-p5 reject multiple underscores present in catalog IDs; element accepts them.
		if ($cardId !== '')
		{
			$event->setElement($cardId);
		}

		if (!$isSuccess)
		{
			$event->setType($slug);
		}

		$event->send();
	}

	public static function resolveAiAgentsScenarioRefusalSlug(string $refusal): string
	{
		return in_array($refusal, self::AI_AGENTS_SCENARIO_REFUSAL_SLUGS, true)
			? $refusal
			: self::SLUG_OTHER
		;
	}

	/**
	 * The event carries known values only: no chart name, no filters and no data of the chart.
	 *
	 * @param string $vizType Chart type as the iframe reported it; empty when the payload was not read.
	 * @param string $refusal One of ChartAnalysisLaunchResult::REFUSALS; ignored on success.
	 * @param string|null $previousStatus Status of the previous answer about the chart, if there was one.
	 */
	public static function sendChartAnalysisLaunchAnalytics(
		string $vizType,
		string $refusal,
		?string $previousStatus,
		bool $isSuccess,
		?bool $isContinuation = null,
	): void
	{
		self::buildChartAnalysisLaunchEvent($vizType, $refusal, $previousStatus, $isSuccess, $isContinuation)->send();
	}

	/**
	 * @param bool|null $isContinuation Whether a successful launch wrote into the chat of an earlier analysis;
	 * null leaves the chat out of the event.
	 */
	public static function buildChartAnalysisLaunchEvent(
		string $vizType,
		string $refusal,
		?string $previousStatus,
		bool $isSuccess,
		?bool $isContinuation = null,
	): AnalyticsEvent
	{
		$event = new AnalyticsEvent(
			$isSuccess ? self::CHART_ANALYSIS_LAUNCHED : self::CHART_ANALYSIS_REFUSED,
			self::TOOL,
			self::CHART_ANALYSIS_CATEGORY,
		);

		$event
			->setSection(self::CHART_ANALYSIS_SECTION)
			->setStatus($isSuccess ? AnalyticsEvent::STATUS_SUCCESS : AnalyticsEvent::STATUS_ERROR)
		;

		if ($vizType !== '')
		{
			$event->setElement(
				preg_match(self::CHART_ANALYSIS_VIZ_TYPE_PATTERN, $vizType) ? $vizType : self::SLUG_OTHER,
			);
		}

		if (!$isSuccess)
		{
			$event->setType(
				in_array($refusal, ChartAnalysisLaunchResult::REFUSALS, true) ? $refusal : self::SLUG_OTHER,
			);
		}

		if (in_array($previousStatus, ChartAnalysisPayload::PREVIOUS_STATUSES, true))
		{
			// p1-p5 accept a single underscore, and the prefix already spends it.
			$event->setP1(self::CHART_ANALYSIS_PREVIOUS_PREFIX . str_replace('_', '', $previousStatus));
		}

		if ($isSuccess && $isContinuation !== null)
		{
			$event->setP2($isContinuation ? self::CHART_ANALYSIS_CHAT_CONTINUED : self::CHART_ANALYSIS_CHAT_NEW);
		}

		return $event;
	}
}
