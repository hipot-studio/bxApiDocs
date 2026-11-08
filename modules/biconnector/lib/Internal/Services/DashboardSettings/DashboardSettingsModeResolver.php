<?php

namespace Bitrix\BIConnector\Internal\Services\DashboardSettings;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Public\Features\PublicLinksControl;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\UserTable;

/**
 * Which part of the dashboard settings the user may open. A public links controller without the settings right
 * gets the reduced mode for the dashboards they can view or that have public links (the registry route):
 * the right to control links depends neither on editing nor on viewing the dashboard.
 */
class DashboardSettingsModeResolver
{
	private readonly AccessController $accessController;
	private readonly SupersetDashboardShareBanRepository $banRepository;
	private readonly SupersetDashboardShareRepository $shareRepository;

	public function __construct(
		?AccessController $accessController = null,
		?SupersetDashboardShareBanRepository $banRepository = null,
		?SupersetDashboardShareRepository $shareRepository = null,
	)
	{
		$serviceLocator = ServiceLocator::getInstance();
		$this->accessController = $accessController ?? AccessController::getCurrent();
		$this->banRepository = $banRepository ?? $serviceLocator->get('biconnector.repository.shareBan');
		$this->shareRepository = $shareRepository ?? $serviceLocator->get('biconnector.repository.share');
	}

	public function resolve(DashboardAccessItem $item): DashboardSettingsMode
	{
		if ($this->canModifySettings($item))
		{
			return DashboardSettingsMode::Full;
		}

		if (
			$this->isPublicLinksControlEnabled()
			&& $this->canControlShares()
			&& (
				$this->accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $item)
				|| $this->shareRepository->existsByDashboardId($item->getId())
			)
		)
		{
			return DashboardSettingsMode::Reduced;
		}

		return DashboardSettingsMode::Denied;
	}

	public function canOpen(DashboardAccessItem $item): bool
	{
		return $this->resolve($item) !== DashboardSettingsMode::Denied;
	}

	/**
	 * @return array{isBanned: bool, canChange: bool}|null Null when the public links control is off.
	 */
	public function getShareSettingState(int $dashboardId): ?array
	{
		if (!$this->isPublicLinksControlEnabled())
		{
			return null;
		}

		return [
			'isBanned' => $this->banRepository->isBanned($dashboardId),
			'canChange' => $this->canControlShares(),
		];
	}

	/**
	 * @return array{
	 *     title: string,
	 *     owner: ?array{id: int, name: string},
	 *     groups: string[],
	 *     dateCreateTimestamp: int,
	 *     dateModifyTimestamp: ?int,
	 * } Not escaped.
	 */
	public function getBaseInfo(Dashboard $dashboard): array
	{
		$ormDashboard = $dashboard->getOrmObject();
		$ormDashboard->fill(['CREATED_BY_ID', 'DATE_CREATE', 'DATE_MODIFY']);
		if (!$ormDashboard->isGroupsFilled())
		{
			$ormDashboard->fillGroups();
		}

		$groups = [];
		foreach ($ormDashboard->getGroups() as $group)
		{
			$groups[] = (string)$group->getName();
		}

		return [
			'title' => $dashboard->getTitle(),
			'owner' => $this->getOwner((int)$ormDashboard->getCreatedById()),
			'groups' => $groups,
			'dateCreateTimestamp' => (int)$ormDashboard->getDateCreate()?->getTimestamp(),
			'dateModifyTimestamp' => $ormDashboard->getDateModify()?->getTimestamp(),
		];
	}

	private function canModifySettings(DashboardAccessItem $item): bool
	{
		$action = $item->getType() === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM
			? ActionDictionary::ACTION_BIC_DASHBOARD_EDIT
			: ActionDictionary::ACTION_BIC_DASHBOARD_MODIFY_SETTINGS
		;

		return $this->accessController->check($action, $item);
	}

	private function canControlShares(): bool
	{
		return $this->accessController->check(ActionDictionary::ACTION_BIC_SHARE_CONTROL);
	}

	private function isPublicLinksControlEnabled(): bool
	{
		return Feature::isEnabled(PublicLinksControl::class);
	}

	/**
	 * @return array{id: int, name: string}|null
	 */
	private function getOwner(int $userId): ?array
	{
		if ($userId <= 0)
		{
			return null;
		}

		$user = UserTable::getRow([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL'],
			'filter' => ['=ID' => $userId],
		]);
		if (!$user)
		{
			return null;
		}

		return [
			'id' => $userId,
			'name' => \CUser::FormatName(\CSite::GetNameFormat(false), $user, true, false),
		];
	}
}
