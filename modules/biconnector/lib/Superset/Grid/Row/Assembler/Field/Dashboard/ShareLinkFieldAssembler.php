<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\BIConnector\Internal\Services\Share\AuthorShareStateService;
use Bitrix\BIConnector\Public\Features\PublicLinksControl;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

class ShareLinkFieldAssembler extends FieldAssembler
{
	/** @var array<int, array|null> Dashboard ID => share data or null */
	private array $shareDataMap = [];

	/** @var array<int, true> */
	private array $bannedDashboardIds = [];

	private bool $canShare;
	private bool $isBanApplied = false;
	private bool $showBanOnHiddenCell = false;

	public function __construct(array $columnIds)
	{
		$this->canShare = AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE);

		parent::__construct($columnIds);
	}

	public function prepareRows(array $rowList): array
	{
		$this->loadShareData($rowList);

		return parent::prepareRows($rowList);
	}

	private function loadShareData(array $rowList): void
	{
		$dashboardIds = [];
		foreach ($rowList as $row)
		{
			if (
				($row['data']['ENTITY_TYPE'] ?? '') === DashboardGroupRepository::TYPE_DASHBOARD
				&& !empty($row['data']['ID'])
			)
			{
				$dashboardIds[] = (int)$row['data']['ID'];
			}
		}

		if (empty($dashboardIds))
		{
			return;
		}

		$this->loadBanData($dashboardIds);

		// The same state as on the detail page, so the popup opened from the list shows the admin switch-off too.
		$this->shareDataMap = $this->getStateService()->getStates(
			$dashboardIds,
			(int)CurrentUser::get()->getId(),
			$this->isControlEnabled(),
		);
	}

	private function getStateService(): AuthorShareStateService
	{
		return ServiceLocator::getInstance()->get('biconnector.service.authorShareState');
	}

	/**
	 * @param int[] $dashboardIds
	 */
	private function loadBanData(array $dashboardIds): void
	{
		$this->isBanApplied = $this->isControlEnabled();
		if (!$this->isBanApplied)
		{
			return;
		}

		$this->showBanOnHiddenCell = $this->canControlShares();

		/** @var SupersetDashboardShareBanRepository $banRepository */
		$banRepository = ServiceLocator::getInstance()->get('biconnector.repository.shareBan');
		$this->bannedDashboardIds = array_fill_keys($banRepository->getBannedDashboardIds($dashboardIds), true);
	}

	protected function isControlEnabled(): bool
	{
		return Feature::isEnabled(PublicLinksControl::class);
	}

	protected function canControlShares(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SHARE_CONTROL);
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$entityType = $row['data']['ENTITY_TYPE'] ?? '';
			if ($entityType !== DashboardGroupRepository::TYPE_DASHBOARD)
			{
				$row['columns'][$columnId] = '';

				continue;
			}

			$dashboardId = (int)($row['data']['ID'] ?? 0);
			$isBanned = isset($this->bannedDashboardIds[$dashboardId]);
			if ($this->isBanApplied)
			{
				$row['data']['IS_SHARE_BANNED'] = $isBanned;
			}

			$status = $row['data']['STATUS'] ?? '';
			$type = $row['data']['TYPE'] ?? '';

			// Drafts can't be shared.
			// FAILED can't be shared either: the share page has no retry for it and shows an endless stub.
			// LOAD/system-NOT_INSTALLED are sharable — opening the share triggers install/retry.
			// Custom NOT_INSTALLED is a transient state with nothing to share yet.
			$isHidden =
				$status === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT
				|| $status === SupersetDashboardTable::DASHBOARD_STATUS_FAILED
				|| ($status === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED && $type === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
			;

			if ($isHidden)
			{
				$row['columns'][$columnId] = $isBanned && $this->showBanOnHiddenCell ? $this->renderBannedCell($dashboardId) : '';

				continue;
			}

			if ($isBanned)
			{
				$row['columns'][$columnId] = $this->renderBannedCell($dashboardId);

				continue;
			}

			$shareData = $this->shareDataMap[$dashboardId] ?? null;
			$isActive = $shareData !== null && ($shareData['isActive'] ?? false);
			$title = $row['data']['TITLE'] ?? '';

			$row['columns'][$columnId] = $this->renderCell($dashboardId, $isActive, $shareData, $type, $title);
		}

		return $row;
	}

	private function renderBannedCell(int $dashboardId): string
	{
		$text = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_GRID_SHARE_LINK_BANNED');
		$hint = htmlspecialcharsbx(Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_GRID_SHARE_LINK_BANNED_HINT') ?? '');

		return <<<HTML
		<span class="dashboard-share-link dashboard-share-link--banned" data-dashboard-id="{$dashboardId}" data-banned="Y" data-hint="{$hint}" data-hint-no-icon>
			{$text}
		</span>
		HTML;
	}

	private function renderCell(int $dashboardId, bool $isActive, ?array $shareData, string $type, string $title): string
	{
		if ($isActive)
		{
			$text = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_GRID_SHARE_LINK_ACTIVE');
			$cssClass = 'dashboard-share-link--active';
		}
		else
		{
			$text = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_GRID_SHARE_LINK_INACTIVE');
			$cssClass = 'dashboard-share-link--inactive';
		}

		$shareDataEncoded = htmlspecialcharsbx(Json::encode($shareData ?? ''));

		if ($this->canShare)
		{
			$onclick = "BX.BIConnector.SupersetDashboardGridManager.Instance.showSharePopup(this)";
			$typeEncoded = htmlspecialcharsbx(mb_strtolower($type));
			$titleEncoded = htmlspecialcharsbx($title);

			return <<<HTML
			<span class="dashboard-share-link {$cssClass}" data-dashboard-id="{$dashboardId}" data-share="{$shareDataEncoded}" data-type="{$typeEncoded}" data-title="{$titleEncoded}" onclick="{$onclick}">
				{$text}
			</span>
			HTML;
		}

		return <<<HTML
		<span class="dashboard-share-link {$cssClass}">
			{$text}
		</span>
		HTML;
	}
}
