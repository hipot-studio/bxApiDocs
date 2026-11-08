<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\DashboardGroupService;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * An empty custom dashboard of the actor, created as a draft, with the checks and texts of
 * create_dashboard. A retry with the same title returns the dashboard the actor already has.
 * A dashboard left NOT_INSTALLED by an earlier attempt is created in Superset by the retry only when
 * the entry point asks for it: create_dashboard keeps answering such a row as it is.
 *
 * Data: the answer of create_dashboard — `id`, `name`, `published` and `reused` on a retry.
 */
final class CreateDashboardOperation
{
	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 */
	public function __construct(?\Closure $accessControllerFactory = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
	}

	/**
	 * @param string $title Trimmed by the operation; blank is refused.
	 * @param bool $resumeNotInstalled Whether a retry finishes in Superset the dashboard an earlier attempt left
	 * NOT_INSTALLED, instead of answering it as it is.
	 */
	public function execute(string $title, Actor $actor, bool $resumeNotInstalled = false): Result
	{
		$result = new Result();
		$userId = $actor->userId;

		if (!Feature::isBuilderEnabled())
		{
			return $result->addError(new Error(
				'BI Builder feature is not enabled on this portal.',
				FailureCode::FeatureDisabled->value,
			));
		}

		$title = trim($title);
		if ($title === '')
		{
			return $result->addError(new Error(
				'name is required and must be a non-empty string.',
				FailureCode::InvalidArgument->value,
			));
		}

		$accessController = ($this->accessControllerFactory)($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return $result->addError(new Error(
				'Access denied: ACTION_BIC_ACCESS is required.',
				FailureCode::AccessDenied->value,
			));
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			return $result->addError(new Error(
				'Access denied: ACTION_BIC_DASHBOARD_EDIT is required to create dashboards.',
				FailureCode::AccessDenied->value,
			));
		}

		$allowedGroups = $accessController->getAllowedGroupValue(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);
		if (empty($allowedGroups))
		{
			return $result->addError(new Error(
				'Access denied: no editable dashboard groups for the current user.',
				FailureCode::AccessDenied->value,
			));
		}

		// A retry after a timeout must not leave a second NOT_INSTALLED row behind.
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

			$unfinished = ($existing['STATUS'] ?? null) === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED
				&& (int)($existing['EXTERNAL_ID'] ?? 0) <= 0
			;
			if ($resumeNotInstalled && $unfinished)
			{
				$dashboard = SupersetDashboardTable::getById($existingId)->fetchObject();
				if ($dashboard !== null)
				{
					return $this->createInSuperset($dashboard, $userId, ['reused' => true]);
				}
			}

			return $result->setData([
				'id' => $existingId,
				'name' => $title,
				// The state of the dashboard found, unlike create_dashboard, which always answered a draft.
				'published' => ($existing['STATUS'] ?? null) === SupersetDashboardTable::DASHBOARD_STATUS_READY,
				'reused' => true,
			]);
		}

		// The local row goes first, so a Superset failure never leaves an orphan dashboard there.
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
			return Refusal::unavailableBi(
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

		return $this->createInSuperset($dashboard, $userId);
	}

	/**
	 * Creates in Superset the dashboard whose local row is NOT_INSTALLED and makes the row a draft.
	 *
	 * @param array<string, mixed> $extraData Added to the answer, such as `reused` of a retry.
	 */
	private function createInSuperset(SupersetDashboard $dashboard, int $userId, array $extraData = []): Result
	{
		$result = new Result();
		$dashboardId = (int)$dashboard->getId();
		$title = (string)$dashboard->getTitle();

		$created = [
			'id' => $dashboardId,
			'name' => $title,
			'published' => false,
		] + $extraData;

		// The row stays NOT_INSTALLED until the instance comes up and resyncs it.
		if (!SupersetInitializer::isSupersetReady())
		{
			return $result->setData($created);
		}

		$response = IntegratorFactory::getInstance()->createEmptyDashboard(['name' => $title]);
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
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
			return Refusal::unavailableBi(
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

		return $result->setData($created);
	}
}
