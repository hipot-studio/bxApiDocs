<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Public\Command\ShareControl\BanDashboardCommand;
use Bitrix\BIConnector\Public\Command\ShareControl\DisableSharesCommand;
use Bitrix\BIConnector\Public\Command\ShareControl\DisableSharesResult;
use Bitrix\BIConnector\Public\Command\ShareControl\UnbanDashboardCommand;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Actions of the public links control. Every action checks the control right itself,
 * so a controller who lost the right is refused on the next call.
 */
class ShareControl extends Controller
{
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_DASHBOARD_NOT_FOUND = 'dashboard_not_found';

	protected function getDefaultPreFilters(): array
	{
		$filters = [
			...parent::getDefaultPreFilters(),
			new BIConstructorAccess(),
		];

		if (Loader::includeModule('intranet'))
		{
			$filters[] = new IntranetUser();
		}

		return $filters;
	}

	/**
	 * @param int[] $ids
	 * @return array{disabledIds: int[], failed: array<array{id: int, message: string}>}|null
	 */
	public function disableSharesAction(array $ids): ?array
	{
		if (!$this->checkControlAccess())
		{
			return null;
		}

		$result = (new DisableSharesCommand(array_map('intval', $ids), $this->getCurrentUserId()))->run();
		if (!$result instanceof DisableSharesResult || !$result->isSuccess())
		{
			Logger::logErrors($result->getErrors(), ['action' => 'sharecontrol.disableShares']);
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_SHARECONTROL_DISABLE_ERROR')));

			return null;
		}

		$failed = [];
		foreach ($result->getFailed() as $shareId => $errorCode)
		{
			$failed[] = [
				'id' => $shareId,
				'message' => $this->getDisableFailureMessage($errorCode),
			];
		}

		return [
			'disabledIds' => $result->getDisabledIds(),
			'failed' => $failed,
		];
	}

	/**
	 * A repeated ban is not an error: the answer reports the dashboard as banned with nothing switched off.
	 *
	 * @return array{isBanned: bool, disabledCount: int}|null
	 */
	public function banDashboardAction(int $dashboardId): ?array
	{
		if (!$this->checkControlAccess() || !$this->getDashboardRow($dashboardId))
		{
			return null;
		}

		$result = (new BanDashboardCommand($dashboardId, $this->getCurrentUserId()))->run();
		if (!$result->isSuccess())
		{
			Logger::logErrors($result->getErrors(), ['action' => 'sharecontrol.banDashboard', 'dashboardId' => $dashboardId]);
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_SHARECONTROL_BAN_ERROR'),
				BanDashboardCommand::ERROR_BAN_CONFLICT,
			));

			return null;
		}

		return [
			'isBanned' => $this->getShareBanRepository()->isBanned($dashboardId),
			'disabledCount' => (int)($result->getData()['disabledCount'] ?? 0),
		];
	}

	/**
	 * @return array{isBanned: bool, disabledCount: int}|null
	 */
	public function unbanDashboardAction(int $dashboardId): ?array
	{
		if (!$this->checkControlAccess() || !$this->getDashboardRow($dashboardId))
		{
			return null;
		}

		$result = (new UnbanDashboardCommand($dashboardId))->run();
		if (!$result->isSuccess())
		{
			Logger::logErrors($result->getErrors(), ['action' => 'sharecontrol.unbanDashboard', 'dashboardId' => $dashboardId]);
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_SHARECONTROL_UNBAN_ERROR')));

			return null;
		}

		return [
			'isBanned' => $this->getShareBanRepository()->isBanned($dashboardId),
			'disabledCount' => 0,
		];
	}

	/**
	 * @return array{activeCount: int, isBanned: bool, title: string}|null
	 */
	public function getDashboardShareSummaryAction(int $dashboardId): ?array
	{
		if (!$this->checkControlAccess())
		{
			return null;
		}

		$dashboard = $this->getDashboardRow($dashboardId);
		if (!$dashboard)
		{
			return null;
		}

		$activeCount = 0;
		foreach ($this->getShareRepository()->getActiveByDashboardId($dashboardId) as $share)
		{
			if (!$share->isExpired())
			{
				$activeCount++;
			}
		}

		return [
			'activeCount' => $activeCount,
			'isBanned' => $this->getShareBanRepository()->isBanned($dashboardId),
			'title' => (string)$dashboard['TITLE'],
		];
	}

	private function checkControlAccess(): bool
	{
		if ($this->canControlShares())
		{
			return true;
		}

		$this->addError(new UserFacingError(
			Loc::getMessage('BICONNECTOR_CONTROLLER_SHARECONTROL_ACCESS_DENIED'),
			self::ERROR_ACCESS_DENIED,
		));

		return false;
	}

	protected function canControlShares(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SHARE_CONTROL);
	}

	private function getDashboardRow(int $dashboardId): ?array
	{
		$row = $dashboardId > 0
			? SupersetDashboardTable::getRow([
				'select' => ['ID', 'TITLE'],
				'filter' => ['=ID' => $dashboardId],
			])
			: null
		;

		if (!$row)
		{
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_SHARECONTROL_DASHBOARD_NOT_FOUND'),
				self::ERROR_DASHBOARD_NOT_FOUND,
			));

			return null;
		}

		return $row;
	}

	private function getDisableFailureMessage(string $errorCode): string
	{
		return $errorCode === DisableSharesResult::ERROR_SHARE_NOT_VALID
			? Loc::getMessage('BICONNECTOR_CONTROLLER_SHARECONTROL_SHARE_NOT_VALID')
			: Loc::getMessage('BICONNECTOR_CONTROLLER_SHARECONTROL_DISABLE_ERROR')
		;
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	private function getShareRepository(): SupersetDashboardShareRepository
	{
		return ServiceLocator::getInstance()->get('biconnector.repository.share');
	}

	private function getShareBanRepository(): SupersetDashboardShareBanRepository
	{
		return ServiceLocator::getInstance()->get('biconnector.repository.shareBan');
	}
}
