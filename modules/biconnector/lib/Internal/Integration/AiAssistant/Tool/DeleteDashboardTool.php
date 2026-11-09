<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DeleteDashboardOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class DeleteDashboardTool extends BaseBiTool
{
	private const DELETION_IMPACT = 'The dashboard will be permanently deleted. Its charts are kept '
		. 'and remain available for other dashboards. This cannot be undone.'
	;
	private const CONFIRM_HINT = 'Show this preview to the user; only AFTER they explicitly approve, '
		. 'call delete_dashboard again with the same dashboardId and confirm=true.'
	;

	public function getName(): string
	{
		return 'delete_dashboard';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Destructive;
	}

	public function getDescription(): string
	{
		return 'Permanently deletes a custom BI dashboard from BI Constructor (Apache '
			. 'Superset). DESTRUCTIVE and irreversible. Only custom dashboards can be '
			. 'deleted — system and marketplace dashboards are rejected. The charts '
			. 'that were on the dashboard are NOT deleted (use delete_chart for those). '
			. 'A dashboard that has been copied by others cannot be deleted. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview (dashboard title) and changes nothing. Relay '
			. 'it to the user, get EXPLICIT approval, then call again with `confirm=true`. Never '
			. 'set `confirm=true` on your own, never chain the two calls, never infer approval '
			. 'from earlier conversation. '
			. 'Returns the preview on the first call, and `{deleted:true, id, name}` on '
			. 'the confirmed call. The preview has a second form: when the dashboard has '
			. 'copies, both calls answer `{requiresConfirmation:false, possible:false, '
			. 'reason:"has_copies"}` and nothing is deleted — report that the deletion is '
			. 'impossible instead of asking the user to confirm it. Idempotent: deleting '
			. 'an already-deleted dashboard is a safe no-op and returns '
			. '`{deleted:true, already_deleted:true}`.';
	}

	public function getInputSchema(): array
	{
		$schema = BuilderArgumentContract::schema(
			BuilderArgumentContract::methodForTool($this->getName()),
			'mcp',
			[
				'dashboardId' => 'Local dashboard id (from create_dashboard or list_dashboards). Positive integer.',
			],
		);
		$schema['properties']['confirm'] = [
			'type' => 'boolean',
			'description' => 'Set to true ONLY after the user has explicitly approved '
				. 'this previewed deletion. Omit or set false to only preview the impact. '
				. 'Do NOT self-confirm or assume approval from earlier context.',
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
			throw self::toMcpException(
				Refusal::error(FailureCode::InvalidArgument, 'dashboardId is required and must be a positive integer.'),
			);
		}

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$operation = ServiceLocator::getInstance()->get(DeleteDashboardOperation::class);
		$actor = new Actor($userId, Channel::Mcp);

		$preview = $operation->preview($dashboardId, $actor);
		if (!$preview->isSuccess())
		{
			throw self::toMcpException($preview);
		}
		$data = $preview->getData();

		if ($data['alreadyDeleted'])
		{
			return DeleteDashboardOperation::toToolAnswer($data);
		}

		if (!$data['possible'])
		{
			return [
				'requiresConfirmation' => false,
				'action' => 'delete_dashboard',
				'id' => $dashboardId,
				'name' => $data['title'],
				'possible' => false,
				'reason' => $data['reason'],
				'explanation' => DeleteDashboardOperation::HAS_COPIES_EXPLANATION,
			];
		}

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'delete_dashboard',
				'id' => $dashboardId,
				'name' => $data['title'],
				'impact' => self::DELETION_IMPACT,
				'confirmHint' => self::CONFIRM_HINT,
			];
		}

		$result = $operation->execute($dashboardId, $actor);
		if (!$result->isSuccess())
		{
			throw self::toMcpException($result);
		}

		return DeleteDashboardOperation::toToolAnswer($result->getData());
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		$dashboardId = (int)($args['dashboardId'] ?? 0);
		if ($dashboardId <= 0)
		{
			throw self::toMcpException(
				Refusal::error(FailureCode::InvalidArgument, 'dashboardId is required and must be a positive integer.'),
			);
		}

		// loadDashboardForWrite enforces: feature flag, existence, custom type,
		// READY/DRAFT status, EDIT ACL and a synced external id.
		$loadResult = $this->loadDashboardForWrite($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			// A dashboard that no longer exists locally is an idempotent no-op —
			// symmetric with delete_chart / delete_dataset. Any other failure
			// (feature gate, non-custom type, status, ACL, sync) stays a real error.
			foreach ($loadResult->getErrors() as $error)
			{
				if ($error->getCode() === 'dashboard_not_found')
				{
					return [
						'deleted' => true,
						'id' => $dashboardId,
						'name' => '',
						'already_deleted' => true,
					];
				}
			}

			throw self::toMcpException($loadResult);
		}
		$ormObject = $loadResult->getData()['dashboard'];

		// Delete needs its own permission on top of the EDIT check above.
		$accessItem = DashboardAccessItem::createFromId($dashboardId);
		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_DELETE, $accessItem))
		{
			throw self::toMcpException(Refusal::error(
				FailureCode::AccessDenied,
				'Access denied: ACTION_BIC_DASHBOARD_DELETE is required to delete dashboards.',
			));
		}

		$name = (string)$ormObject->getTitle();
		$externalId = (int)$ormObject->getExternalId();

		// Placed before the confirmation branch, so the preview and the confirmed
		// call answer alike and the destructive budget is never spent on a deletion
		// the domain forbids. A rule of the subject area is not a failure, hence a
		// structured answer instead of an exception.
		if ((new Dashboard($ormObject))->hasCopies())
		{
			return [
				'requiresConfirmation' => false,
				'action' => 'delete_dashboard',
				'id' => $dashboardId,
				'name' => $name,
				'possible' => false,
				'reason' => 'has_copies',
				'explanation' => 'Other dashboards have been copied from this one, so it cannot be '
					. 'deleted. Delete the copies first.',
			];
		}

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'delete_dashboard',
				'id' => $dashboardId,
				'name' => $name,
				'impact' => 'The dashboard will be permanently deleted. Its charts are kept '
					. 'and remain available for other dashboards. This cannot be undone.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call delete_dashboard again with the same dashboardId and confirm=true.',
			];
		}

		// Charged here and not earlier: a preview, a denied or an already-deleted
		// call returns above and must not cost the user a destructive slot.
		$this->spendDestructiveBudget($userId, 'dashboard', $dashboardId);

		$response = IntegratorFactory::getInstance()->deleteDashboard([$externalId], false);
		if ($response->hasErrors())
		{
			// Already gone on the Superset side — drop the local row and report success.
			if ($response->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
			{
				$deleteResult = $ormObject->delete();
				if (!$deleteResult->isSuccess())
				{
					AiToolsLogger::logErrors(
						$deleteResult->getErrors(),
						[
							'stage' => 'delete_dashboard.local_delete_not_found',
							'user_id' => $userId,
							'dashboard_id' => $dashboardId,
							'external_id' => $externalId,
						],
					);
				}

				return [
					'deleted' => true,
					'id' => $dashboardId,
					'name' => $name,
					'already_deleted' => true,
				];
			}

			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'delete_dashboard',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		$deleteResult = $ormObject->delete();
		if (!$deleteResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$deleteResult->getErrors(),
				[
					'stage' => 'delete_dashboard.local_delete',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		return [
			'deleted' => true,
			'id' => $dashboardId,
			'name' => $name,
		];
	}
}
