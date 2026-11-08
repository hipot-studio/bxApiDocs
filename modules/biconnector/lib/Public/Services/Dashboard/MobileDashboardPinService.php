<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Services\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Configuration\Feature;

final class MobileDashboardPinService
{
	public const MAX_PINNED_DASHBOARDS = 5;

	public const ERROR_NOT_FOUND = 'MOBILE_DASHBOARD_NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'MOBILE_DASHBOARD_ACCESS_DENIED';
	public const ERROR_LIMIT_EXCEEDED = 'MOBILE_DASHBOARD_PIN_LIMIT_EXCEEDED';
	public const ERROR_SAVE_FAILED = 'MOBILE_DASHBOARD_PIN_SAVE_FAILED';

	private const OPTION_CATEGORY = 'biconnector';
	private const OPTION_NAME = 'mobile_pinned_dashboards';
	public const ERROR_FEATURE_DISABLED = 'MOBILE_DASHBOARD_PINNING_DISABLED';

	public function getPinnedDashboardIds(int $userId): array
	{
		if (!Feature::isMobileDashboardPinningEnabled())
		{
			return [];
		}

		$rawIds = \CUserOptions::GetOption(
			category: self::OPTION_CATEGORY,
			name: self::OPTION_NAME,
			default_value: [],
			user_id: $userId,
		);

		$pinnedDashboardIds = $this->normalizeDashboardIds($rawIds, self::MAX_PINNED_DASHBOARDS);

		if ($pinnedDashboardIds === [])
		{
			return [];
		}

		$existingRows = Model\SupersetDashboardTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $pinnedDashboardIds)
			->fetchAll()
		;

		$existingIds = array_map('intval', array_column($existingRows, 'ID'));
		$actualIds = array_values(array_intersect($pinnedDashboardIds, $existingIds));

		if ($actualIds !== $pinnedDashboardIds)
		{
			$saved = \CUserOptions::SetOption(
				category: self::OPTION_CATEGORY,
				name: self::OPTION_NAME,
				value: $actualIds,
				user_id: $userId,
			);

			if (!$saved)
			{
				Logger::logErrors([
					new Error(
						'Failed to remove deleted dashboards from mobile pins.',
						self::ERROR_SAVE_FAILED,
					),
				]);
			}
		}

		return $actualIds;
	}

	public function isPinned(int $dashboardId, int $userId): bool
	{
		return in_array($dashboardId, $this->getPinnedDashboardIds($userId), true);
	}

	public function pin(int $dashboardId, int $userId): Result
	{
		if (!Feature::isMobileDashboardPinningEnabled())
		{
			return $this->createErrorResult(
				'BICONNECTOR_MOBILE_DASHBOARD_PIN_ERROR_DISABLED',
				self::ERROR_FEATURE_DISABLED,
				[],
			);
		}

		$pinnedDashboardIds = $this->getPinnedDashboardIds($userId);

		if (in_array($dashboardId, $pinnedDashboardIds, true))
		{
			return $this->createSuccessResult($pinnedDashboardIds, changed: false);
		}

		$dashboard = (new SupersetController())->getDashboardRepository()->getById($dashboardId);

		if (!$dashboard)
		{
			return $this->createErrorResult(
				'BICONNECTOR_MOBILE_DASHBOARD_PIN_ERROR_NOT_FOUND',
				self::ERROR_NOT_FOUND,
				$pinnedDashboardIds,
			);
		}

		if (!$this->canView($dashboard, $userId))
		{
			return $this->createErrorResult(
				'BICONNECTOR_MOBILE_DASHBOARD_PIN_ERROR_ACCESS_DENIED',
				self::ERROR_ACCESS_DENIED,
				$pinnedDashboardIds,
			);
		}

		if (count($pinnedDashboardIds) >= self::MAX_PINNED_DASHBOARDS)
		{
			return $this->createErrorResult(
				'BICONNECTOR_MOBILE_DASHBOARD_PIN_ERROR_LIMIT_EXCEEDED',
				self::ERROR_LIMIT_EXCEEDED,
				$pinnedDashboardIds
			);
		}

		array_unshift($pinnedDashboardIds, $dashboardId);

		return $this->savePinnedDashboardIds(
			$userId,
			$pinnedDashboardIds,
			changed: true,
		);
	}

	public function unpin(int $dashboardId, int $userId): Result
	{
		if (!Feature::isMobileDashboardPinningEnabled())
		{
			return $this->createErrorResult(
				'BICONNECTOR_MOBILE_DASHBOARD_PIN_ERROR_DISABLED',
				self::ERROR_FEATURE_DISABLED,
				[],
			);
		}

		$pinnedDashboardIds = $this->getPinnedDashboardIds($userId);

		if (!in_array($dashboardId, $pinnedDashboardIds, true))
		{
			return $this->createSuccessResult($pinnedDashboardIds, changed: false);
		}

		$pinnedDashboardIds = array_values(array_diff($pinnedDashboardIds, [$dashboardId]));

		return $this->savePinnedDashboardIds(
			$userId,
			$pinnedDashboardIds,
			changed: true,
		);
	}

	private function canView (Model\Dashboard $dashboard, int $userId): bool
	{
		if (!(new AccessController($userId))->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
		{
			return false;
		}

		if (!(MarketAccessManager::getInstance()->isDashboardAvailableByType($dashboard->getType())))
		{
			return false;
		}

		$appId = $dashboard->getAppId();

		return $appId === '' || DashboardTariffConfigurator::isAvailableDashboard($appId);
	}

	private function savePinnedDashboardIds(int $userId, array $pinnedDashboardIds, bool $changed): Result
	{
		if (!\CUserOptions::SetOption(
				category: self::OPTION_CATEGORY,
				name: self::OPTION_NAME,
				value: $pinnedDashboardIds,
				user_id: $userId,
			)
		)
		{
			return $this->createErrorResult(
				'BICONNECTOR_MOBILE_DASHBOARD_PIN_ERROR_SAVE',
				self::ERROR_SAVE_FAILED,
				$this->getPinnedDashboardIds($userId),
			);
		}

		return $this->createSuccessResult($pinnedDashboardIds, $changed);
	}

	private function createSuccessResult(array $pinnedDashboardIds, bool $changed): Result
	{
		return (new Result())->setData([
			'pinnedDashboardIds' => $pinnedDashboardIds,
			'changed' => $changed,
		]);
	}

	private function createErrorResult(string $phraseId, string $errorCode, array $pinnedDashboardIds): Result
	{
		return (new Result())
			->setData([
				'pinnedDashboardIds' => $pinnedDashboardIds,
				'changed' => false,
			])
			->addError(
				new UserFacingError(
					(string)Loc::getMessage($phraseId),
					$errorCode,
				)
			);
	}

	private function normalizeDashboardIds(mixed $value, ?int $limit = null): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$result = [];
		$seen = [];

		foreach ($value as $rawDashboardId)
		{
			if (!is_int($rawDashboardId) && !is_string($rawDashboardId))
			{
				continue;
			}

			$dashboardId = filter_var($rawDashboardId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

			if ($dashboardId === false || isset($seen[$dashboardId]))
			{
				continue;
			}

			$seen[$dashboardId] = true;
			$result[] = $dashboardId;

			if ($limit !== null && count($result) >= $limit)
			{
				break;
			}
		}

		return $result;
	}
}
