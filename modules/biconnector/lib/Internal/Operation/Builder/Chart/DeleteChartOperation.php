<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\DestructiveBudget;
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetIdentity;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;

/**
 * Deletes a chart with the checks and the texts of delete_chart. A chart that is already gone is
 * an idempotent success, not a refusal; the destructive budget is spent by the deletion only.
 */
final class DeleteChartOperation
{
	private const OBJECT_TYPE = 'chart';

	private readonly ChartWriteGuard $guard;
	private readonly SupersetIdentity $identity;

	/**
	 * @param DestructiveBudget|null $budget Without it, the budget over the limiter the service locator holds
	 *     at the moment of the deletion, so a limiter replaced after the operation was built is charged.
	 */
	public function __construct(
		?ChartWriteGuard $guard = null,
		private readonly ?DestructiveBudget $budget = null,
		?SupersetIdentity $identity = null,
	)
	{
		$this->guard = $guard ?? new ChartWriteGuard();
		$this->identity = $identity ?? new SupersetIdentity();
	}

	/**
	 * Data: `id`, `name`, `dashboards` (raw Superset consumers of the chart), `alreadyDeleted`.
	 */
	public function preview(int $chartId, Actor $actor): Result
	{
		return $this->inspect($chartId, $actor);
	}

	/**
	 * Data: the answer of delete_chart — `deleted`, `id`, `name` and, when the chart was gone, `already_deleted`.
	 */
	public function execute(int $chartId, Actor $actor): Result
	{
		$inspected = $this->inspect($chartId, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		['name' => $name, 'alreadyDeleted' => $alreadyDeleted] = $inspected->getData();
		if ($alreadyDeleted)
		{
			return self::deleted($chartId, $name, true);
		}

		$spent = $this->getBudget()->spend($actor, self::OBJECT_TYPE, $chartId);
		if (!$spent->isSuccess())
		{
			return $spent;
		}

		$response = IntegratorFactory::getInstance()->deleteChart($chartId);
		$classified = (new EntityLookup())->classify($response, $chartId);
		if (!$classified->isSuccess())
		{
			if ($classified->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return self::deleted($chartId, $name, true);
			}

			return $classified;
		}

		return self::deleted($chartId, $name, false);
	}

	private function inspect(int $chartId, Actor $actor): Result
	{
		$allowed = $this->guard->forChart($chartId, $actor);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		$identityResult = $this->identity->forRead($actor->userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$lookup = (new EntityLookup())->chart($chartId, $identityResult->getData()['user']);
		if (!$lookup->isSuccess())
		{
			if ($lookup->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return (new Result())->setData([
					'id' => $chartId,
					'name' => '',
					'dashboards' => [],
					'alreadyDeleted' => true,
				]);
			}

			return $lookup;
		}

		$chartData = $lookup->getData()['entity'];
		$dashboards = $chartData['dashboards'] ?? [];
		if (!is_array($dashboards))
		{
			return Refusal::unavailableBi([], ['stage' => 'delete_chart.load', 'chart_id' => $chartId]);
		}

		$editable = $this->guard->checkEditable($dashboards, $actor);
		if (!$editable->isSuccess())
		{
			return $editable;
		}

		return (new Result())->setData([
			'id' => $chartId,
			'name' => (string)($chartData['slice_name'] ?? $chartData['chart_name'] ?? ''),
			'dashboards' => $dashboards,
			'alreadyDeleted' => false,
		]);
	}

	private function getBudget(): DestructiveBudget
	{
		return $this->budget
			?? new DestructiveBudget(ServiceLocator::getInstance()->get('biconnector.service.biToolsRateLimiter'))
		;
	}

	private static function deleted(int $chartId, string $name, bool $alreadyDeleted): Result
	{
		$answer = [
			'deleted' => true,
			'id' => $chartId,
			'name' => $name,
		];
		if ($alreadyDeleted)
		{
			$answer['already_deleted'] = true;
		}

		return (new Result())->setData($answer);
	}
}
