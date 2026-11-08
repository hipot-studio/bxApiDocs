<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\DashboardGroupService;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\CreateDashboardOperation;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

final class CreateDashboardTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'create_dashboard';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Creates a new empty BI dashboard (custom type). '
			. 'The dashboard is created as a DRAFT and is not visible to other users until '
			. 'published via publish_dashboard. '
			. 'After creating the dashboard, use set_dashboard_content to attach charts, set '
			. 'the layout, and add filters; use update_dashboard_metadata to change the title; use '
			. 'publish_dashboard / unpublish_dashboard to flip visibility. Returns the new '
			. 'dashboard `id` (local Bitrix id, use this for all subsequent BI tools), '
			. '`name`, and `published` flag.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Dashboard display name. Must be non-empty.',
				],
			],
			'required' => ['name'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		Loader::includeModule('biconnector');

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(CreateDashboardOperation::class)->execute(
			(string)($args['name'] ?? ''),
			new Actor($userId, Channel::Mcp),
		);
		if (!$result->isSuccess())
		{
			throw self::toMcpException($result);
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!Feature::isBuilderEnabled())
		{
			throw new McpException('BI Builder feature is not enabled on this portal.');
		}

		$title = trim((string)($args['name'] ?? ''));
		if ($title === '')
		{
			throw new McpException('name is required and must be a non-empty string.');
		}

		$accessController = $this->getAccessController($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			throw new McpException('Access denied: ACTION_BIC_DASHBOARD_EDIT is required to create dashboards.');
		}

		$allowedGroups = $accessController->getAllowedGroupValue(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);
		if (empty($allowedGroups))
		{
			throw new McpException('Access denied: no editable dashboard groups for the current user.');
		}

		// Idempotency: if the agent retries (e.g. on a network timeout) we must
		// not create a clone — duplicates are particularly painful for dashboards
		// because a NOT_INSTALLED row would linger in the DB forever. Look up
		// the user's own custom dashboard with the same title in any live status
		// and return it as if it had just been created, but mark `reused=true`
		// so the agent can tell.
		$existing = SupersetDashboardTable::query()
			->setSelect(['ID', 'EXTERNAL_ID', 'STATUS'])
			->where('TITLE', $title)
			->where('TYPE', SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
			->where('CREATED_BY_ID', $userId)
			->whereIn('STATUS', [
				SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
				SupersetDashboardTable::DASHBOARD_STATUS_READY,
				SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED,
			])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;
		if (is_array($existing))
		{
			$existingId = (int)($existing['ID'] ?? 0);
			AiToolsLogger::logInfo(
				'create_dashboard reused existing dashboard (idempotent retry).',
				[
					'stage' => 'create_dashboard.reused',
					'user_id' => $userId,
					'name' => $title,
					'dashboard_id' => $existingId,
					'status' => (string)($existing['STATUS'] ?? ''),
				],
			);

			return [
				'id' => $existingId,
				'name' => $title,
				'published' => false,
				'reused' => true,
			];
		}

		// 1) Local save first with NOT_INSTALLED status. Mirrors
		//    dashboard.edit/class.php::createDashboard so a Superset failure
		//    never leaves an orphan dashboard in Superset without a local row.
		$dashboard = SupersetDashboardTable::createObject();
		$dashboard
			->setTitle($title)
			->setType(SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
			->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
			->setCreatedById($userId)
		;

		$saveResult = $dashboard->save();
		if (!$saveResult->isSuccess())
		{
			throw self::unavailableBiException(
				$saveResult->getErrors(),
				[
					'stage' => 'create_dashboard.local_save',
					'user_id' => $userId,
					'name' => $title,
				],
			);
		}

		$dashboardId = (int)$dashboard->getId();

		DashboardGroupService::saveDashboardGroupBindings($dashboardId, $allowedGroups);

		// 2) Superset is opt-in: if the instance isn't ready, leave the local row
		//    in NOT_INSTALLED — installDashboardAction will resync once the
		//    instance comes up, matching the canonical flow.
		if (!SupersetInitializer::isSupersetReady())
		{
			return [
				'id' => $dashboardId,
				'name' => $title,
				'published' => false,
			];
		}

		$response = IntegratorFactory::getInstance()->createEmptyDashboard(['name' => $title]);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'create_dashboard.superset',
					'user_id' => $userId,
					'name' => $title,
					'dashboard_id' => $dashboardId,
				],
			);
		}

		$data = $response->getData();
		$externalId = (int)($data['body']['id'] ?? 0);
		if ($externalId <= 0)
		{
			throw self::unavailableBiException(
				[new Error('Empty external id from createEmptyDashboard response')],
				[
					'stage' => 'create_dashboard.superset.no_external_id',
					'user_id' => $userId,
					'name' => $title,
					'dashboard_id' => $dashboardId,
				],
			);
		}

		$dashboard
			->setExternalId($externalId)
			->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_DRAFT)
		;
		$saveResult = $dashboard->save();
		if (!$saveResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$saveResult->getErrors(),
				[
					'stage' => 'create_dashboard.local_save_external_id',
					'user_id' => $userId,
					'name' => $title,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		return [
			'id' => $dashboardId,
			'name' => $title,
			'published' => false,
		];
	}
}
