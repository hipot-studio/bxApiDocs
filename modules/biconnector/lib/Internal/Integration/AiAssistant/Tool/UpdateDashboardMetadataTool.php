<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftPolicy;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\RenameDashboardOperation;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class UpdateDashboardMetadataTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'update_dashboard_metadata';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Updates custom BI dashboard metadata (currently only the display title). '
			. 'Does NOT touch charts, filters, layout, or published state — use '
			. 'set_dashboard_content for layout/filters and publish_dashboard / '
			. 'unpublish_dashboard to flip visibility. The new title must be a '
			. 'non-empty string. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview of the rename (old title vs new title) and '
			. 'changes nothing. Relay it to the user, get EXPLICIT approval, then call again '
			. 'with `confirm=true`. Never set `confirm=true` on your own, never chain the two '
			. 'calls, never infer approval from earlier conversation.';
	}

	public function getInputSchema(): array
	{
		$schema = BuilderArgumentContract::schema(
			BuilderArgumentContract::methodForTool($this->getName()),
			'mcp',
			['dashboardId' => 'Local dashboard id (from create_dashboard or list_dashboards). Positive integer.',
				'name' => 'New dashboard title. Must be non-empty after trim; maximum 128 characters before trim.',
			],
		);
		$schema['properties']['confirm'] = [
			'type' => 'boolean',
			'description' => 'Set to true ONLY after the user has explicitly approved this rename. Omit or set '
				. 'false to only preview. Do NOT self-confirm or assume approval from earlier context.',
		];

		return $schema;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		Loader::includeModule('biconnector');

		$dashboardId = (int)($args['dashboardId'] ?? 0);
		if ($dashboardId <= 0)
		{
			throw new McpException('dashboardId is required and must be a positive integer.');
		}

		$title = (string)($args['name'] ?? '');
		$actor = new Actor($userId, Channel::Mcp);
		$policy = $this->getDraftPolicy($userId);
		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$operation = ServiceLocator::getInstance()->get(RenameDashboardOperation::class);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			$preview = $operation->preview($dashboardId, $title, $actor, $policy);
			if (!$preview->isSuccess())
			{
				throw self::toMcpException($preview);
			}

			return [
				'requiresConfirmation' => true,
				'action' => 'update_dashboard_metadata',
				'id' => $dashboardId,
				'name' => [
					'old' => $preview->getData()['oldTitle'],
					'new' => $preview->getData()['newTitle'],
				],
				'impact' => 'The dashboard will be renamed. Charts, filters, layout and '
					. 'published state are not affected.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call '
					. 'update_dashboard_metadata again with the same arguments and confirm=true.',
			];
		}

		$result = $operation->execute($dashboardId, $title, $actor, $policy);
		if (!$result->isSuccess())
		{
			throw self::toMcpException($result);
		}

		return $result->getData();
	}

	/**
	 * The draft-only rule holds for MCP once the alignment of its behaviour with REST is switched on.
	 */
	private function getDraftPolicy(int $userId): DraftPolicy
	{
		return (new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_ALIGNMENT, $userId)
			? DraftPolicy::Enforce
			: DraftPolicy::Skip
		;
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		$dashboardId = (int)($args['dashboardId'] ?? 0);
		if ($dashboardId <= 0)
		{
			throw new McpException('dashboardId is required and must be a positive integer.');
		}

		$newTitle = trim((string)($args['name'] ?? ''));
		if ($newTitle === '')
		{
			throw new McpException('name is required and must be a non-empty string.');
		}

		$loadResult = $this->loadDashboardForWrite($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			throw self::toMcpException($loadResult);
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'update_dashboard_metadata',
				'id' => $dashboardId,
				'name' => [
					'old' => (string)$dashboard->getTitle(),
					'new' => $newTitle,
				],
				'impact' => 'The dashboard will be renamed. Charts, filters, layout and '
					. 'published state are not affected.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call update_dashboard_metadata again with the same arguments and confirm=true.',
			];
		}

		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, [
			'dashboard_title' => $newTitle,
		]);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'update_dashboard_metadata',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		$dashboard->setTitle($newTitle);
		$saveResult = $dashboard->save();
		if (!$saveResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$saveResult->getErrors(),
				[
					'stage' => 'update_dashboard_metadata.local_save',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		return [
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY,
		];
	}
}
