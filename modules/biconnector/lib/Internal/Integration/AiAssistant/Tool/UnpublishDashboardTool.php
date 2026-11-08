<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Loader;

final class UnpublishDashboardTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'unpublish_dashboard';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Destructive;
	}

	public function getDescription(): string
	{
		return 'Moves a custom BI dashboard back to DRAFT, hiding it from other '
			. 'portal users. '
			. 'IMPORTANT — side effect: this revokes ALL public share links for '
			. 'the dashboard (mirrors the UI toggleDraft action). Existing share '
			. 'URLs stop working immediately and CANNOT be restored — recipients '
			. 'will need a freshly issued share link if the dashboard is '
			. 'published again. '
			. 'Idempotent: unpublishing an already-draft dashboard is a safe no-op, '
			. 'and repeating the call after a failure is safe too — it resumes from '
			. 'the step that failed and carries the operation through. A failed call '
			. 'reports which steps did complete. Does NOT touch charts, filters, or '
			. 'the title. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview (dashboard title and the share-link '
			. 'revocation impact) and changes nothing. Relay it to the user, get EXPLICIT '
			. 'approval, then call again with `confirm=true`. Never set `confirm=true` on your '
			. 'own, never chain the two calls, never infer approval from earlier conversation.';
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
						. 'unpublishing this dashboard (which permanently revokes its public share '
						. 'links). Omit or set false to only preview. Do NOT self-confirm or assume '
						. 'approval from earlier context.',
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
		$ormObject = $loadResult->getData()['dashboard'];
		$externalId = (int)$ormObject->getExternalId();
		$wasPublished = $ormObject->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY;

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'unpublish_dashboard',
				'id' => $dashboardId,
				'name' => (string)$ormObject->getTitle(),
				'impact' => $wasPublished
					? 'The dashboard will be moved to DRAFT and hidden from other portal users. '
						. 'ALL its public share links will be permanently revoked and cannot be restored.'
					: 'The dashboard is already a draft; this is a safe no-op (no live share links to revoke).',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call unpublish_dashboard again with the same dashboardId and confirm=true.',
			];
		}

		// Charged here and not earlier: a preview or a denied call returns above
		// and must not cost the user a destructive slot. Everything below —
		// including the irreversible share-link revocation — is the actual change.
		$this->spendDestructiveBudget($userId, 'dashboard', $dashboardId);

		// The order of share revocation, remote unpublish and local status change is
		// the domain's, not the tool's: the agent and the portal user go through the
		// very same transition.
		$unpublishResult = (new Dashboard($ormObject))->toggleDraft(false);
		if (!$unpublishResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$unpublishResult->getErrors(),
				[
					'stage' => 'unpublish_dashboard',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);

			throw self::toMcpException($unpublishResult);
		}

		return [
			'id' => $dashboardId,
			'name' => (string)$ormObject->getTitle(),
			'published' => false,
		];
	}
}
