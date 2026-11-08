<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardJsonMetadataReader;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

final class PublishDashboardTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'publish_dashboard';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Publishes a custom BI dashboard so other portal users can see it. '
			. 'Moves the dashboard from DRAFT to READY. '
			. 'HARD INVARIANT — the dashboard must already have a `time_range` '
			. 'filter configured before it can be published; otherwise charts '
			. 'silently ignore the missing date filter and return unfiltered SQL. '
			. 'If the dashboard has no `time_range` filter yet, call '
			. 'set_dashboard_content first to add one (use "Last quarter" as '
			. 'default if the user did not specify a period). '
			. 'Idempotent: republishing an already-published dashboard is a '
			. 'no-op. Does NOT touch charts, filters, or the title. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview of what will be published (dashboard id and '
			. 'title) and changes nothing. Relay it to the user, get EXPLICIT approval, then '
			. 'call again with `confirm=true`. Never set `confirm=true` on your own, never '
			. 'chain the two calls, never infer approval from earlier conversation.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'description' => 'Local dashboard id (from create_dashboard or list_dashboards). '
						. 'Positive integer.',
					'minimum' => 1,
				],
				'confirm' => [
					'type' => 'boolean',
					'description' => 'Set to true ONLY after the user has explicitly approved '
						. 'publishing this dashboard. Omit or set false to only preview without '
						. 'changing visibility. Do NOT self-confirm or assume approval from '
						. 'earlier context.',
				],
			],
			'required' => ['dashboardId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		$dashboardId = (int)($args['dashboardId'] ?? 0);
		if ($dashboardId <= 0)
		{
			throw self::toMcpException(
				Refusal::error(FailureCode::InvalidArgument, 'dashboardId is required and must be a positive integer.'),
			);
		}

		$loadResult = $this->loadDashboardForWrite($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			throw self::toMcpException($loadResult);
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		// HARD INVARIANT: every published dashboard must have a time_range filter —
		// without one, charts silently ignore the missing dashboard date filter
		// and return unfiltered SQL. Enforce before flipping the published flag.
		$jsonMetadata = DashboardJsonMetadataReader::fetch(
			$externalId,
			$userId,
			$dashboardId,
			'publish_dashboard',
		);
		if (!DashboardJsonMetadataReader::hasTimeRange($jsonMetadata))
		{
			throw new McpException(
				'Dashboard has no `time_range` filter configured. Every dashboard '
				. 'must have a date filter before it is published. Call '
				. 'set_dashboard_content with a `filters` array containing at '
				. 'least one `time_range` entry (use "Last quarter" as default '
				. 'if the user did not specify a period), then retry '
				. 'publish_dashboard.'
			);
		}

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'publish_dashboard',
				'id' => $dashboardId,
				'name' => (string)$dashboard->getTitle(),
				'impact' => 'The dashboard will be published (moved from DRAFT to READY) and '
					. 'become visible to other portal users. Charts, filters and the title '
					. 'are not affected.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call publish_dashboard again with the same dashboardId and confirm=true.',
			];
		}

		if ((new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_ALIGNMENT, $userId))
		{
			return $this->publishThroughDomain($dashboard, $userId, $dashboardId, $externalId);
		}

		return $this->legacyPublish($dashboard, $userId, $dashboardId, $externalId);
	}

	/**
	 * The portal user and the agent go through the same transition, so the remote answer is judged
	 * by the domain. Its reasons stay in the log: the agent gets the same refusal as before.
	 */
	private function publishThroughDomain(
		SupersetDashboard $dashboard,
		int $userId,
		int $dashboardId,
		int $externalId,
	): array
	{
		$context = [
			'stage' => 'publish_dashboard',
			'user_id' => $userId,
			'dashboard_id' => $dashboardId,
			'external_id' => $externalId,
		];

		$publishResult = (new Dashboard($dashboard))->toggleDraft(true);
		if (!$publishResult->isSuccess())
		{
			throw self::unavailableBiException($publishResult->getErrors(), $context);
		}

		// The domain does not check its local save, so the status is read back: the agent must not
		// report a dashboard as published while it is still a draft. A repeated call is safe.
		$savedStatus = SupersetDashboardTable::getRow([
			'select' => ['STATUS'],
			'filter' => ['=ID' => $dashboardId],
		])['STATUS'] ?? null;
		if ($savedStatus !== SupersetDashboardTable::DASHBOARD_STATUS_READY)
		{
			throw self::unavailableBiException(
				[new Error('Dashboard was published in BI, but its local status was not saved.')],
				['stage' => 'publish_dashboard.local_save'] + $context,
			);
		}

		return [
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => true,
		];
	}

	private function legacyPublish(
		SupersetDashboard $dashboard,
		int $userId,
		int $dashboardId,
		int $externalId,
	): array
	{
		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, [
			'published' => true,
		]);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'publish_dashboard',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		$dashboard->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_READY);
		$saveResult = $dashboard->save();
		if (!$saveResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$saveResult->getErrors(),
				[
					'stage' => 'publish_dashboard.local_save',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		return [
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => true,
		];
	}
}
