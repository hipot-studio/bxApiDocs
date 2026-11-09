<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorableImplementation;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Public\Provider\DashboardDetailInfoProvider;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardRepository;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Entity\ValueObject\DashboardDetailInfo\DashboardInfo as DashboardInfoValueObject;
use Bitrix\BIConnector\Internal\Services\DashboardSettings\DashboardSettingsModeResolver;

Loader::includeModule("biconnector");

class BiconnectorApachesupersetDashboardDetailInfoComponent extends CBitrixComponent implements Controllerable, Errorable
{
	use ErrorableImplementation;

	private DashboardRepository $dashboardRepository;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->errorCollection = new ErrorCollection();
		$this->dashboardRepository = new DashboardRepository(IntegratorFactory::getInstance());
	}

	public function onPrepareComponentParams($arParams): array
	{
		$arParams['DASHBOARD_ID'] = (int)($arParams['DASHBOARD_ID'] ?? 0);

		return parent::onPrepareComponentParams($arParams);
	}

	public function configureActions(): array
	{
		return [];
	}

	public function executeComponent(): void
	{
		$this->prepareInitialResult();
		$this->includeComponentTemplate();
	}

	public function getDashboardDataAction(int $dashboardId, bool $refreshMarket = false): ?array
	{
		$dashboard = $this->dashboardRepository->getById($dashboardId);
		if (!$dashboard)
		{
			$this->errorCollection->setError(
				new Error(Loc::getMessage('BICONNECTOR_APACHESUPERSET_DASHBOARD_DETAIL_INFO_NOT_FOUND'))
			);

			return null;
		}

		if (!$this->canViewDashboard($dashboard))
		{
			$this->errorCollection->setError(
				new Error(Loc::getMessage($this->getViewAccessErrorCode($dashboard)))
			);

			return null;
		}

		$dashboardData = $this->prepareDashboardData($dashboard, $refreshMarket);
		if ($dashboardData === null)
		{
			$this->errorCollection->setError(
				new Error(Loc::getMessage('BICONNECTOR_APACHESUPERSET_DASHBOARD_DETAIL_INFO_NOT_FOUND'))
			);

			return null;
		}

		$dashboardData['CAN_MODIFY_SETTINGS'] = $this->canModifySettingsDashboard($dashboard);
		$dashboardData['CAN_DELETE'] = $this->canDeleteDashboard($dashboard);

		return [
			'dashboard' => $dashboardData,
		];
	}

	private function prepareDashboardData(Dashboard $dashboard, bool $refreshMarket = false): ?array
	{
		$dashboardInfo = $this->getDashboardDetailInfoProvider()->getByDashboard($dashboard, $refreshMarket);
		if (!$dashboardInfo)
		{
			return null;
		}

		$publishedById = (int)$dashboardInfo->getPublishedById();
		$updatedById = (int)$dashboardInfo->getUpdatedById();
		$users = $this->getUsers([$publishedById, $updatedById]);

		return [
			'TITLE' => $dashboardInfo->getTitle(),
			'TYPE' => $dashboardInfo->getType(),
			'STATUS' => $dashboard->getStatus(),
			'APP_CODE' => $dashboardInfo->getAppCode(),
			'VIEWS_COUNT' => $dashboardInfo->getViewsCount(),
			'PARTNER_NAME' => $dashboardInfo->getPartnerName(),
			'ICON' => $this->prepareIcon($dashboardInfo),
			'IMAGES' => $dashboardInfo->getImages(),
			'DESCRIPTION' => $dashboardInfo->getDescription()
				? $this->prepareDescription($dashboardInfo->getDescription(), $dashboardInfo->getType())
				: null
			,
			'PUBLISHED_BY_ID' => $publishedById,
			'PUBLISHED_BY_NAME' => $users[$publishedById]['NAME'] ?? null,
			'PUBLISHED_BY_PERSONAL_PHOTO' => $users[$publishedById]['PERSONAL_PHOTO'] ?? null,
			'PUBLISHED_DATE' => $dashboardInfo->getPublishedDate()
				? $this->prepareDate($dashboardInfo->getPublishedDate())
				: null
			,
			'UPDATED_BY_ID' => $updatedById,
			'UPDATED_BY_NAME' => $users[$updatedById]['NAME'] ?? null,
			'UPDATED_BY_PERSONAL_PHOTO' => $users[$updatedById]['PERSONAL_PHOTO'] ?? null,
			'UPDATED_DATE' => $dashboardInfo->getUpdatedDate()
				? $this->prepareDate($dashboardInfo->getUpdatedDate())
				: null
			,
			'RATING_INFO' => $dashboardInfo->getRatingInfo(),
		];
	}

	protected function prepareInitialResult(): void
	{
		$this->arResult['ERROR_MESSAGES'] = [];
		$this->arResult['DASHBOARD_TITLE'] = '';
		$this->arResult['CAN_MODIFY_SETTINGS'] = false;
		$this->arResult['CAN_DELETE'] = false;

		$dashboardId = (int)$this->arParams['DASHBOARD_ID'];
		if ($dashboardId <= 0)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_APACHESUPERSET_DASHBOARD_DETAIL_INFO_NOT_FOUND');

			return;
		}

		$dashboard = $this->dashboardRepository->getById($dashboardId);
		if (!$dashboard)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_APACHESUPERSET_DASHBOARD_DETAIL_INFO_NOT_FOUND');

			return;
		}

		if (!$this->canViewDashboard($dashboard))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage($this->getViewAccessErrorCode($dashboard));

			return;
		}

		$this->arResult['DASHBOARD_TITLE'] = $dashboard->getTitle();
		$this->arResult['CAN_MODIFY_SETTINGS'] = $this->canModifySettingsDashboard($dashboard);
		$this->arResult['CAN_DELETE'] = $this->canDeleteDashboard($dashboard);
	}

	private function canViewDashboard(Dashboard $dashboard): bool
	{
		return AccessController::getCurrent()->check(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
			$this->createDashboardAccessItem($dashboard),
		);
	}

	/**
	 * The settings also open in the reduced mode for a public links controller who can only view the dashboard.
	 */
	private function canModifySettingsDashboard(Dashboard $dashboard): bool
	{
		return (new DashboardSettingsModeResolver())->canOpen($this->createDashboardAccessItem($dashboard));
	}

	private function canDeleteDashboard(Dashboard $dashboard): bool
	{
		return AccessController::getCurrent()->check(
			ActionDictionary::ACTION_BIC_DASHBOARD_DELETE,
			$this->createDashboardAccessItem($dashboard),
		);
	}

	private function createDashboardAccessItem(Dashboard $dashboard): DashboardAccessItem
	{
		return DashboardAccessItem::createFromArray([
			'ID' => $dashboard->getId(),
			'TYPE' => $dashboard->getType(),
			'STATUS' => $dashboard->getStatus(),
		]);
	}

	private function getViewAccessErrorCode(Dashboard $dashboard): string
	{
		return $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT
			? 'BICONNECTOR_APACHESUPERSET_DASHBOARD_DETAIL_INFO_NOT_FOUND'
			: 'BICONNECTOR_APACHESUPERSET_DASHBOARD_DETAIL_INFO_ACCESS_ERROR'
		;
	}

	/**
	 * @param int[] $userIds
	 *
	 * @return array<int, array{NAME: ?string, PERSONAL_PHOTO: ?string}>
	 */
	private function getUsers(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter($userIds, static fn(int $id): bool => $id > 0)));
		if ($userIds === [])
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL', 'PERSONAL_PHOTO'])
			->whereIn('ID', $userIds)
			->exec()
		;

		$users = [];
		$nameFormat = \CSite::GetNameFormat(false);
		while ($row = $rows->fetch())
		{
			$name = (string)\CUser::FormatName($nameFormat, $row, true, false);
			$photoId = (int)$row['PERSONAL_PHOTO'];

			$users[(int)$row['ID']] = [
				'NAME' => $name !== '' ? $name : null,
				'PERSONAL_PHOTO' => $photoId > 0 ? $this->resizeImageGet($photoId, 22, 22) : null,
			];
		}

		return $users;
	}

	private function resizeImageGet(int $fileId, int $width, int $height): ?string
	{
		$imageFile = \CFile::getFileArray($fileId);
		if ($imageFile === false)
		{
			return null;
		}

		$file = \CFile::resizeImageGet(
			$imageFile,
			compact('width', 'height'),
			BX_RESIZE_IMAGE_EXACT,
			false
		);

		$src = (string)($file['src'] ?? '');

		return $src !== '' ? $src : null;
	}

	private function prepareDescription(string $description, string $dashboardType): string
	{
		if ($dashboardType === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$description = $this->convertBbCodeToHtml($description);
		}

		$Sanitizer = new CBXSanitizer();
		$Sanitizer->SetLevel(CBXSanitizer::SECURE_LEVEL_MIDDLE);
		$Sanitizer->ApplyDoubleEncode(false);

		return $Sanitizer->SanitizeHtml($description);
	}

	private function convertBbCodeToHtml(string $description): string
	{
		if ($description === '')
		{
			return '';
		}

		return (new CTextParser())->convertText($description);
	}

	private function prepareDate(DateTime $dateTime): string
	{
		return $dateTime->format(Date::getFormat());
	}

	private function prepareIcon(DashboardInfoValueObject $dashboardInfo): ?string
	{
		$icon = $dashboardInfo->getIcon();
		if ($icon === null || $icon === '')
		{
			return null;
		}

		if ($dashboardInfo->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			return $icon;
		}

		$iconId = (int)$icon;
		if ($iconId <= 0)
		{
			return null;
		}

		return $this->resizeImageGet($iconId, 390, 220);
	}

	private function getDashboardDetailInfoProvider(): DashboardDetailInfoProvider
	{
		return ServiceLocator::getInstance()->get('biconnector.provider.dashboardDetailInfo');
	}
}
