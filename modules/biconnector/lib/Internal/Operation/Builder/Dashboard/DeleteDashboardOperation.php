<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\DestructiveBudget;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Deletes a custom dashboard with the checks and the texts of delete_dashboard.
 *
 * Both steps run the same checks, so a rule of the subject area (the dashboard has copies) answers alike
 * on the preview and on the deletion; the destructive budget is spent by the deletion only. A dashboard
 * that is already gone is an idempotent success, not a refusal.
 */
final class DeleteDashboardOperation
{
	public const HAS_COPIES_REASON = 'has_copies';
	public const HAS_COPIES_EXPLANATION = 'Other dashboards have been copied from this one, so it cannot be '
		. 'deleted. Delete the copies first.'
	;

	private const ACCESS_DENIED_MESSAGE =
		'Access denied: ACTION_BIC_DASHBOARD_DELETE is required to delete dashboards.'
	;

	private const PUBLISHED_MESSAGE = 'Dashboard is published and cannot be deleted here: only a draft can be '
		. 'deleted. A published dashboard is deleted by a person in BI Builder.'
	;

	private readonly DashboardGuard $guard;
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
		$this->guard = new DashboardGuard($this->accessControllerFactory);
	}

	/**
	 * Data: `id`, `title`, `alreadyDeleted`, `possible` and, when the deletion is not possible, `reason`.
	 */
	public function preview(int $dashboardId, Actor $actor): Result
	{
		$inspected = $this->inspect($dashboardId, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		$data = $inspected->getData();
		unset($data['dashboard']);

		return (new Result())->setData($data);
	}

	/**
	 * Data: `id`, `title`, `alreadyDeleted`.
	 *
	 * @param DraftPolicy $policy Enforce refuses a published dashboard: REST deletes drafts only, while
	 *     delete_dashboard asks a person to confirm the deletion instead.
	 */
	public function execute(int $dashboardId, Actor $actor, DraftPolicy $policy = DraftPolicy::Skip): Result
	{
		$inspected = $this->inspect($dashboardId, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		$preview = $inspected->getData();
		if ($preview['alreadyDeleted'])
		{
			return self::deleted($dashboardId, '', true);
		}

		if (
			$policy === DraftPolicy::Enforce
			&& $preview['dashboard']->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY
		)
		{
			return (new Result())->addError(new Error(self::PUBLISHED_MESSAGE, FailureCode::PublishedReport->value));
		}

		if (!$preview['possible'])
		{
			return (new Result())
				->addError(new Error(self::HAS_COPIES_EXPLANATION, FailureCode::DomainRule->value))
				->setData(['reason' => $preview['reason']])
			;
		}

		$spent = DestructiveBudget::forCurrentLimiter()->spend($actor, 'dashboard', $dashboardId);
		if (!$spent->isSuccess())
		{
			return $spent;
		}

		/** @var SupersetDashboard $dashboard */
		$dashboard = $preview['dashboard'];
		$title = $preview['title'];
		$externalId = (int)$dashboard->getExternalId();
		$context = [
			'stage' => 'delete_dashboard',
			'user_id' => $actor->userId,
			'dashboard_id' => $dashboardId,
			'external_id' => $externalId,
		];

		$response = IntegratorFactory::getInstance()->deleteDashboard([$externalId], false);
		if ($response->hasErrors())
		{
			// Already gone on the Superset side: drop the local row and report success.
			if ($response->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
			{
				$this->deleteLocalRow($dashboard, ['stage' => 'delete_dashboard.local_delete_not_found'] + $context);

				return self::deleted($dashboardId, $title, true);
			}

			return Refusal::unavailableBi($response->getErrors(), $context);
		}

		$this->deleteLocalRow($dashboard, ['stage' => 'delete_dashboard.local_delete'] + $context);

		return self::deleted($dashboardId, $title, false);
	}

	/**
	 * The answer of delete_dashboard to a deletion, which biconnector.builder.dashboard.delete repeats.
	 *
	 * @param array{id: int, title: string, alreadyDeleted: bool} $deleted Data of execute().
	 */
	public static function toToolAnswer(array $deleted): array
	{
		$answer = [
			'deleted' => true,
			'id' => $deleted['id'],
			'name' => $deleted['title'],
		];
		if ($deleted['alreadyDeleted'])
		{
			$answer['already_deleted'] = true;
		}

		return $answer;
	}

	/**
	 * The checks of both steps, in the order of the tool: the loader of a dashboard for writing, the right
	 * to delete, then the rule of the subject area. Data also holds the SupersetDashboard as `dashboard`.
	 */
	private function inspect(int $dashboardId, Actor $actor): Result
	{
		$loaded = $this->guard->forWrite($dashboardId, $actor);
		if (!$loaded->isSuccess())
		{
			// A dashboard that no longer exists locally is an idempotent no-op, as delete_chart and
			// delete_dataset answer; any other refusal of the loader stays a refusal.
			if ($loaded->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return (new Result())->setData([
					'id' => $dashboardId,
					'title' => '',
					'alreadyDeleted' => true,
					'possible' => true,
				]);
			}

			return $loaded;
		}

		$accessController = ($this->accessControllerFactory)($actor->userId);
		$accessItem = DashboardAccessItem::createFromId($dashboardId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_DELETE, $accessItem))
		{
			return (new Result())->addError(new Error(self::ACCESS_DENIED_MESSAGE, FailureCode::AccessDenied->value));
		}

		/** @var SupersetDashboard $dashboard */
		$dashboard = $loaded->getData()['dashboard'];
		$data = [
			'id' => $dashboardId,
			'title' => (string)$dashboard->getTitle(),
			'alreadyDeleted' => false,
			'possible' => true,
			'dashboard' => $dashboard,
		];

		if ((new Dashboard($dashboard))->hasCopies())
		{
			$data['possible'] = false;
			$data['reason'] = self::HAS_COPIES_REASON;
		}

		return (new Result())->setData($data);
	}

	private function deleteLocalRow(SupersetDashboard $dashboard, array $context): void
	{
		$deleteResult = $dashboard->delete();
		if (!$deleteResult->isSuccess())
		{
			AiToolsLogger::logErrors($deleteResult->getErrors(), $context);
		}
	}

	private static function deleted(int $dashboardId, string $title, bool $alreadyDeleted): Result
	{
		return (new Result())->setData([
			'id' => $dashboardId,
			'title' => $title,
			'alreadyDeleted' => $alreadyDeleted,
		]);
	}
}
