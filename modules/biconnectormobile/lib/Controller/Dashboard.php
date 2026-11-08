<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Controller;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Public\Provider\SupersetDashboardListProvider;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\Filter\DashboardFilterBuilder;
use Bitrix\BIConnectorMobile\ActionFilter\MobileBIFeatureAccess;
use Bitrix\BIConnectorMobile\Dto\DashboardListFilter;
use Bitrix\BIConnectorMobile\Filter\BiDashboardListContext;
use Bitrix\BIConnectorMobile\Public\Feature\UnifiedFiltersFlag;
use Bitrix\BIConnectorMobile\Service\DashboardDescriptionService;
use Bitrix\BIConnectorMobile\Service\DashboardPreparationService;
use Bitrix\BIConnectorMobile\Service\DashboardViewStateService;
use Bitrix\Main\Config;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\File\Internal\FileVersionTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Filter\AppliedFilterPointer;
use Bitrix\Mobile\Filter\FilterRequest;
use Bitrix\Mobile\UI\StatefulList\BaseController;
use Bitrix\MobileApp\Janative\Manager;
use Bitrix\BIConnector\Public\Services\Dashboard\MobileDashboardPinService;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\BIConnector\Configuration\Feature;

Loader::requireModule('mobile');
Loader::requireModule('mobileapp');

class Dashboard extends BaseController
{
	protected const PREFIX = 'biconnectormobile.Dashboard';

	// The list row shows a 24px avatar; the asset is resized at 2x for retina screens.
	private const AUTHOR_AVATAR_SIZE = 48;

	// Same badges the web grid puts in the "created by" cell for non-user authors, see
	// biconnector/lib/Superset/Grid/Row/Assembler/Field/Dashboard/CreatedByFieldAssembler.php
	private const AUTHOR_ICON_SYSTEM = '/bitrix/images/biconnector/superset-dashboard-grid/icon-type-system.png';
	private const AUTHOR_ICON_MARKET = '/bitrix/images/biconnector/superset-dashboard-grid/icon-type-market.png';

	// Owned by biconnector: DashboardManager::DASHBOARD_NOTIFY_TAG, the tag its readiness events are
	// pushed by. The web startup template names it literally as well.
	private const PULL_WATCH_TAG = 'superset_dashboard';

	// The screen of a single report, the one the list opens. Same name the JN list holds.
	private const DETAIL_COMPONENT = 'biconnector:biconnector.dashboard.detail';

	public function configureActions(): array
	{
		$actions = [];

		// Every action of the controller answers a screen and writes nothing into the session. Holding the
		// session lock while the report is prepared would freeze the whole application for the user.
		foreach (['getList', 'getViewState', 'getDescription', 'prepareView', 'subscribeToUpdates', 'pin', 'unpin'] as $action)
		{
			$actions[$action] = [
				'+prefilters' => [
					new ActionFilter\CloseSession(),
				],
			];
		}

		return $actions;
	}

	public function pinAction(
		int $dashboardId,
		DashboardListFilter $filter,
		CurrentUser $user
	): array
	{
		return $this->changePinState(
			$dashboardId,
			$filter,
			$user,
			pin: true,
		);
	}

	public function unpinAction(
		int $dashboardId,
		DashboardListFilter $filter,
		CurrentUser $user
	): array
	{
		return $this->changePinState(
			$dashboardId,
			$filter,
			$user,
			pin: false,
		);
	}

	private function changePinState(
		int $dashboardId,
		DashboardListFilter $filter,
		CurrentUser $user,
		bool $pin
	): array
	{
		$userId = (int)$user->getId();
		$service = new MobileDashboardPinService();

		$result = $pin
			? $service->pin($dashboardId, $userId)
			: $service->unpin($dashboardId, $userId)
		;

		$data = $result->getData();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [
				'item' => null,
				'pinnedDashboardIds' => $data['pinnedDashboardIds'] ?? [],
			];
		}

		if (!empty($data['changed']))
		{
			DashboardManager::notifyDashboardListChanged();
		}

		$item = (new SupersetDashboardListProvider())->getMobileDashboardItem(
			$dashboardId,
			$userId,
			$filter->searchString,
			$filter->groupId > 0 ? $filter->groupId : null,
			$filter->scopeCode !== '' ? $filter->scopeCode : null,
		);

		if ($item !== null)
		{
			$item = $this->fillAuthorNames([$item])[0] ?? null;
		}

		return [
			'item' => $item,
			'pinnedDashboardIds' => $data['pinnedDashboardIds'] ?? [],
		];
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = [
			new ActionFilter\Authentication(),
			new ActionFilter\Csrf(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Scope(ActionFilter\Scope::NOT_REST),
			// The feature flag is checked before tariff, tool and rights: a disabled feature must answer
			// FEATURE_DISABLED rather than an upgrade offer.
			new MobileBIFeatureAccess(),
		];

		// The filter of the tariff and the rights belongs to biconnector, so on a portal without it there is
		// nothing to build here - and nothing to guard either: MobileBIFeatureAccess above has already
		// refused the action.
		if (Loader::includeModule('biconnector'))
		{
			$filters[] = new BIConstructorAccess();
		}

		return $filters;
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				DashboardListFilter::class,
				'filter',
				static fn($className, array $filter = []) => DashboardListFilter::make($filter),
			),
		];
	}

	public function getListAction(DashboardListFilter $filter, PageNavigation $pageNavigation): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$presetsEnabled = Loader::includeModule('biconnector')
			&& Feature::isDashboardFilterPresetsEnabled();

		$presetFilter = [];
		$staleConditions = [];

		// The filter belongs to the root list alone. A zone host shows the section it was opened for,
		// and a folder shows what it holds: the pointer of the applied filter is shared with the root
		// list, so reading it here would narrow a folder by conditions the user never applied in it.
		if ($presetsEnabled && $filter->scopeCode === '' && $filter->groupId === 0)
		{
			$listContext = new BiDashboardListContext();
			$options = $listContext->getOptions();

			$presetId = $filter->presetId;
			$requestNamesForm = false;

			if (FilterRequest::usesStoredFilter($presetId, Config\Feature::isEnabled(UnifiedFiltersFlag::class)))
			{
				// A screen that has not read its filter state yet names no preset, so the list is
				// opened under the filter the session already holds - the last one applied here, or
				// the default preset while the session holds none - instead of showing everything.
				// The pointer belongs to the session, while the presets it names are shared with the
				// web. It is read as it stands: `default_filter` names the form and no conditions,
				// while resolving it into the form would revive whatever the shared temporary preset
				// still keeps - conditions the user has already dropped elsewhere.
				$presetId = (string)$options->getCurrentFilterId();
			}
			else
			{
				$presetId = FilterRequest::resolvePresetId($presetId);
				$requestNamesForm = ($presetId === FilterRequest::TMP_PRESET_ID);
			}

			// A request naming the form is answered by the values it carries, and a form with none of
			// them is a form the user has cleared: reading the stored ones instead brings back the
			// conditions the phone has just dropped.
			$settings = $requestNamesForm
				? FilterRequest::buildTmpPresetSettings(['tmpFields' => $filter->tmpFields])
				: FilterRequest::buildSavedPresetSettings($presetId, $options->getFilterSettings($presetId) ?: [])
			;

			AppliedFilterPointer::forList($options)->rememberSettings($presetId, $settings);

			$built = (new DashboardFilterBuilder($userId))->build(
				$settings['fields'] ?? [],
				$filter->searchString,
			);
			$presetFilter = $built->ormFilter;
			$staleConditions = $this->describeStaleConditions($built->stale, $listContext);
		}

		$result = (new SupersetDashboardListProvider())->getMobileList(
			$userId,
			$filter->searchString,
			$filter->groupId > 0 ? $filter->groupId : null,
			$pageNavigation->getLimit(),
			$pageNavigation->getOffset(),
			withTotal: false,
			scopeCode: $filter->scopeCode !== '' ? $filter->scopeCode : null,
			presetFilter: $presetFilter,
		);

		return [
			'items' => $this->fillAuthorNames($result['items']),
			// The version the client opens the report screen at. It would otherwise read the version off
			// `availableComponents` - a map the framework bakes into the script of the list screen, which the
			// application keeps cached until that script itself changes. A change in the report screen alone
			// never reaches the map, and the device keeps opening the report by the previous script. The list
			// asks for its data on every entry, so the version stays current.
			'detailComponentVersion' => Manager::getComponentVersion(self::DETAIL_COMPONENT),
			'isPinningEnabled' => Feature::isMobileDashboardPinningEnabled(),
			'isFilterPresetsEnabled' => $presetsEnabled,
			'staleConditions' => $staleConditions,
		];
	}

	private function describeStaleConditions(array $stale, BiDashboardListContext $listContext): array
	{
		if (empty($stale))
		{
			return [];
		}

		$fields = [];
		foreach ($listContext->getFieldArrays() as $field)
		{
			$fields[$field['id']] = $field['name'] ?? $field['id'];
		}

		return array_map(
			static fn(array $condition): array => $condition + ['fieldName' => $fields[$condition['fieldId']] ?? ''],
			$stale,
		);
	}

	/**
	 * State of the report screen: the client mounts the Superset frame only for `ready`, waits on
	 * `preparing` and shows the refusal screen on `error`. `Instance.getStatus` is not extended with it
	 * on purpose - that action serves the list and its key set is a contract of its own.
	 */
	public function getViewStateAction(int $dashboardId): array
	{
		$state = (new DashboardViewStateService())->resolve($dashboardId)->toArray();
		$state['isPinningEnabled'] = Feature::isMobileDashboardPinningEnabled();

		return $state;
	}

	/**
	 * The description of the report as plain text, the same one the web shows beside the title of the
	 * report. An action of its own on purpose: for a report of Bitrix24 or of Marketplace the description
	 * comes from Marketplace over HTTP, and that request must not delay the opening of the report, so the
	 * screen asks for the description after it is mounted and the header gets the icon when the answer
	 * arrives. An empty answer means the header shows no icon at all.
	 */
	public function getDescriptionAction(int $dashboardId): array
	{
		return [
			'description' => (new DashboardDescriptionService())->getForDashboard($dashboardId),
		];
	}

	/**
	 * Puts the report in a state where it can be shown - installs it and brings the instance up - and
	 * answers at once, without waiting for either. Idempotent: a preparation already running is not
	 * started a second time, and a report that needs no preparation is left alone.
	 */
	public function prepareViewAction(int $dashboardId): array
	{
		$state = (new DashboardViewStateService())->resolve($dashboardId);
		if (!$state->isPreparing())
		{
			return [
				'started' => false,
				'state' => $state->toArray(),
			];
		}

		return [
			'started' => (new DashboardPreparationService())->start($dashboardId, $state),
			'state' => $state->toArray(),
		];
	}

	/**
	 * Registers the user as a watcher of the report tag, the same way the web startup template does
	 * (biconnector.apachesuperset.dashboard.detail/templates/.default/startup.php). Without it the
	 * portal sends no readiness event and the screen would only learn about it from the safety poll.
	 */
	public function subscribeToUpdatesAction(): array
	{
		$subscribed = false;
		if (Loader::includeModule('pull'))
		{
			\CPullWatch::Add((int)CurrentUser::get()->getId(), self::PULL_WATCH_TAG, true);
			$subscribed = true;
		}

		return [
			'tag' => self::PULL_WATCH_TAG,
			'subscribed' => $subscribed,
		];
	}

	/**
	 * Enriches dashboard items with the author display name (`author`) and the author avatar path
	 * (`authorAvatar`), mirroring the web grid CreatedByFieldAssembler: SYSTEM -> Bitrix24,
	 * MARKET -> Marketplace, CUSTOM -> user name and photo (names in one query, photos in two more).
	 * Groups have no author and are skipped.
	 *
	 * @param array $items
	 * @return array
	 */
	private function fillAuthorNames(array $items): array
	{
		$authorIds = [];
		foreach ($items as $item)
		{
			if (
				($item['entityType'] ?? '') === 'dashboard'
				&& ($item['type'] ?? '') === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM
				&& (int)($item['authorId'] ?? 0) > 0
			)
			{
				$authorIds[(int)$item['authorId']] = true;
			}
		}

		$names = [];
		$photoIds = [];
		if (!empty($authorIds))
		{
			$nameFormat = \CSite::GetNameFormat(false);
			$users = \Bitrix\Main\UserTable::getList([
				'filter' => ['@ID' => array_keys($authorIds)],
				'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE', 'PERSONAL_PHOTO'],
			]);
			while ($user = $users->fetch())
			{
				$userId = (int)$user['ID'];
				$names[$userId] = \CUser::FormatName($nameFormat, $user, true, false);

				$photoId = (int)($user['PERSONAL_PHOTO'] ?? 0);
				if ($photoId > 0)
				{
					$photoIds[$userId] = $photoId;
				}
			}
		}

		$avatars = $this->getAvatarUrls($photoIds);

		$systemAuthor = Loc::getMessage('BICONNECTORMOBILE_DASHBOARD_AUTHOR_SYSTEM');
		$marketAuthor = Loc::getMessage('BICONNECTORMOBILE_DASHBOARD_AUTHOR_MARKET');

		foreach ($items as &$item)
		{
			if (($item['entityType'] ?? '') !== 'dashboard')
			{
				continue;
			}

			$type = $item['type'] ?? '';
			if ($type === SupersetDashboardTable::DASHBOARD_TYPE_MARKET)
			{
				$item['author'] = $marketAuthor;
				$item['authorAvatar'] = self::AUTHOR_ICON_MARKET;
			}
			elseif ($type === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM)
			{
				$item['author'] = $systemAuthor;
				$item['authorAvatar'] = self::AUTHOR_ICON_SYSTEM;
			}
			else
			{
				$authorId = (int)($item['authorId'] ?? 0);
				$item['author'] = $names[$authorId] ?? '';
				$item['authorAvatar'] = $avatars[$authorId] ?? '';
			}
		}
		unset($item);

		return $items;
	}

	/**
	 * Resizes the photos of the page without reading b_file per author: given a file id, CFile::ResizeImageGet
	 * reads the record itself, which would be a query per author.
	 *
	 * The path is answered raw and relative, the way the mobile core answers avatars
	 * (mobile/lib/Provider/UserRepository::getAvatar): the domain the application is connected to is known
	 * on the client alone, and it is the client that percent-encodes the path before loading it.
	 *
	 * @param array<int, int> $photoIds User id to file id, users without a photo left out.
	 * @return array<int, string> User id to avatar path, users whose photo cannot be resized left out.
	 */
	private function getAvatarUrls(array $photoIds): array
	{
		if (empty($photoIds))
		{
			return [];
		}

		$files = $this->getFileRecords(array_values(array_unique($photoIds)));

		$urls = [];
		foreach ($photoIds as $userId => $photoId)
		{
			if (!isset($files[$photoId]))
			{
				continue;
			}

			$avatar = \CFile::ResizeImageGet(
				$files[$photoId],
				['width' => self::AUTHOR_AVATAR_SIZE, 'height' => self::AUTHOR_AVATAR_SIZE],
				BX_RESIZE_IMAGE_EXACT,
				false,
			);

			$src = $avatar['src'] ?? '';
			if ($src !== '')
			{
				$urls[$userId] = $src;
			}
		}

		return $urls;
	}

	/**
	 * Reads the file records the way CFile::GetFileArray builds them - the whole row plus SRC, because they go on
	 * to the resize events as they are - but for the whole page at once. Two queries whatever the number of
	 * authors: the versions of the requested files, then the records of both the files and their versions.
	 *
	 * @param int[] $fileIds
	 * @return array<int, array> Requested file id to the record to resize, missing files left out.
	 */
	private function getFileRecords(array $fileIds): array
	{
		$versionIds = [];
		$versions = FileVersionTable::getList([
			'select' => ['ORIGINAL_ID', 'VERSION_ID'],
			'filter' => ['@ORIGINAL_ID' => $fileIds],
		]);
		while ($version = $versions->fetch())
		{
			$versionIds[(int)$version['ORIGINAL_ID']] = (int)$version['VERSION_ID'];
		}

		$rows = [];
		$records = \CFile::GetList([], ['@ID' => array_merge($fileIds, array_values($versionIds))]);
		while ($file = $records->Fetch())
		{
			$file['SRC'] = \CFile::GetFileSRC($file);
			$rows[(int)$file['ID']] = $file;
		}

		$files = [];
		foreach ($fileIds as $fileId)
		{
			// CFile::GetByID resolves a file to the newest record of itself and its versions, and the web grid
			// mirrored here goes through it. An avatar mask lives in a version, while PERSONAL_PHOTO keeps
			// pointing at the original, so without this the list would show the same avatar unmasked.
			$candidateIds = isset($versionIds[$fileId]) ? [$fileId, $versionIds[$fileId]] : [$fileId];
			rsort($candidateIds);

			foreach ($candidateIds as $candidateId)
			{
				if (isset($rows[$candidateId]))
				{
					$files[$fileId] = $rows[$candidateId];
					break;
				}
			}
		}

		return $files;
	}
}
