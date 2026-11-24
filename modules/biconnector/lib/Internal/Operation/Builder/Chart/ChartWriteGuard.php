<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/** Checks chart writes against the current consumers or the owner of an orphan. */
final class ChartWriteGuard
{
	public const NOT_EDITABLE_MESSAGE =
		'You can edit this chart only if you have edit rights on at least one dashboard that uses it.'
	;
	public const UNAVAILABLE_BI_MESSAGE =
		'The BI request could not be completed right now: the BI service may be busy or still syncing. '
		. 'Try again in a moment.'
	;

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
	 * The builder is on and the actor has access to BI.
	 */
	public function forBuilder(Actor $actor): Result
	{
		$enabled = self::checkBuilderEnabled();
		if (!$enabled->isSuccess())
		{
			return $enabled;
		}

		return $this->checkBiAccess($actor);
	}

	/**
	 * The same for an existing chart, with the identifier checked in between, as the tools do.
	 */
	public function forChart(int $chartId, Actor $actor): Result
	{
		$enabled = self::checkBuilderEnabled();
		if (!$enabled->isSuccess())
		{
			return $enabled;
		}

		if ($chartId <= 0)
		{
			return Refusal::error(FailureCode::InvalidArgument, 'chartId is required and must be a positive integer.');
		}

		return $this->checkBiAccess($actor);
	}

	public function can(string $action, Actor $actor): bool
	{
		return ($this->accessControllerFactory)($actor->userId)->check($action);
	}

	/**
	 * @param array $chartDashboards Raw Superset `dashboards` of the chart: `id` is the EXTERNAL_ID of a dashboard.
	 */
	public function checkEditable(array $chartDashboards, Actor $actor, array $owners = []): Result
	{
		if ($chartDashboards === [])
		{
			return (new ChartAuthorAccess($this->accessControllerFactory))->check($owners, $actor);
		}

		$externalIds = [];
		foreach ($chartDashboards as $dashboard)
		{
			if (!is_array($dashboard))
			{
				continue;
			}

			$rawId = $dashboard['id'] ?? null;
			if (!is_int($rawId) && !is_string($rawId))
			{
				continue;
			}
			$externalId = (int)$rawId;
			if ($externalId > 0 && (string)$externalId === (string)$rawId)
			{
				$externalIds[$externalId] = $externalId;
			}
		}

		if (empty($externalIds))
		{
			return Refusal::error(FailureCode::AccessDenied, self::NOT_EDITABLE_MESSAGE);
		}

		$rows = SupersetDashboardTable::getList([
			'select' => ['ID', 'EXTERNAL_ID'],
			'filter' => ['=EXTERNAL_ID' => array_values($externalIds)],
		])->fetchAll();

		$accessController = ($this->accessControllerFactory)($actor->userId);
		foreach ($rows as $row)
		{
			$localId = (int)($row['ID'] ?? 0);
			if (
				$localId > 0
				&& $accessController->check(
					ActionDictionary::ACTION_BIC_DASHBOARD_EDIT,
					DashboardAccessItem::createFromId($localId),
				)
			)
			{
				return new Result();
			}
		}

		return Refusal::error(FailureCode::AccessDenied, self::NOT_EDITABLE_MESSAGE);
	}

	/**
	 * A failure of Superset on a chart, worded as unavailableBiException() of the tools.
	 *
	 * @param Error[] $errors
	 */
	public static function unavailableBi(array $errors, array $context): Result
	{
		$actAsRefusal = Refusal::actAsFailure($errors, $context);
		if ($actAsRefusal !== null)
		{
			return $actAsRefusal;
		}

		AiToolsLogger::logWarning($errors, $context);

		return Refusal::error(FailureCode::Unavailable, self::UNAVAILABLE_BI_MESSAGE);
	}

	private static function checkBuilderEnabled(): Result
	{
		if (!Feature::isBuilderEnabled())
		{
			return Refusal::error(FailureCode::FeatureDisabled, 'BI Builder feature is not enabled on this portal.');
		}

		return new Result();
	}

	private function checkBiAccess(Actor $actor): Result
	{
		if (!$this->can(ActionDictionary::ACTION_BIC_ACCESS, $actor))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied: ACTION_BIC_ACCESS is required.');
		}

		return new Result();
	}
}
