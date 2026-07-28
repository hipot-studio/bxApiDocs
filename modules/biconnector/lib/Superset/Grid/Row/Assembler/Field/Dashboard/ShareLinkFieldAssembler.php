<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

class ShareLinkFieldAssembler extends FieldAssembler
{
	/** @var array<int, array|null> Dashboard ID => share data or null */
	private array $shareDataMap = [];

	private bool $canShare;

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

		$currentUserId = (int)CurrentUser::get()->getId();
		$shares = SupersetDashboardShareTable::getList([
			'filter' => [
				'=DASHBOARD_ID' => $dashboardIds,
				'=CREATED_BY_ID' => $currentUserId,
			],
		])->fetchCollection();

		foreach ($shares as $share)
		{
			$isActive = $share->getActive() === 'Y'
				&& $share->getDateExpire()
				&& $share->getDateExpire()->getTimestamp() >= time()
			;
			$this->shareDataMap[$share->getDashboardId()] = [
				'isActive' => $isActive,
				'password' => $share->getPassword(),
				'dateExpireTimestamp' => $share->getDateExpire() ? $share->getDateExpire()->getTimestamp() : null,
			];
		}
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
				$row['columns'][$columnId] = '';

				continue;
			}

			$shareData = $this->shareDataMap[$dashboardId] ?? null;
			$isActive = $shareData !== null && ($shareData['isActive'] ?? false);
			$title = $row['data']['TITLE'] ?? '';

			$row['columns'][$columnId] = $this->renderCell($dashboardId, $isActive, $shareData, $type, $title);
		}

		return $row;
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
