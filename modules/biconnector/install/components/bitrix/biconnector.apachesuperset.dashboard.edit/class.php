<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\DashboardGroupService;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupBindingTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardUrlParameterTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\Dashboard\Metadata\DashboardMetadataBuilder;
use Bitrix\BIConnector\Superset\Dashboard\Metadata\MetadataSection\NativeFilterConfigurationSection;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnector\Superset\UI\Period\DefaultPeriodLabelBuilder;
use Bitrix\BIConnector\Integration\UI\FileUploader;
use Bitrix\BIConnector\Integration\UI\FileUploaderController\DashboardInfoUploaderController;
use Bitrix\BIConnector\Internal\Entity\ValueObject\DashboardCreate\DashboardSaveData;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardInfoGalleryMapper;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardInfoMapper;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoGalleryRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoRepository;
use Bitrix\BIConnector\Internal\Services\DashboardInfo\FileCleanupService;
use Bitrix\BIConnector\Public\Command\DashboardInfo\AddSupersetDashboardInfoCommand;
use Bitrix\BIConnector\Public\Command\DashboardInfo\UpdateSupersetDashboardInfoCommand;
use Bitrix\BIConnector\Public\Command\DashboardInfoGallery\AddSupersetDashboardInfoGalleryCommand;
use Bitrix\BIConnector\Public\Command\DashboardInfoGallery\DeleteSupersetDashboardInfoGalleryCommand;
use Bitrix\BIConnector\Public\Command\DashboardInfoGallery\UpdateSupersetDashboardInfoGalleryCommand;
use Bitrix\BIConnector\Public\Provider\SupersetDashboardInfoGalleryProvider;
use Bitrix\BIConnector\Public\Provider\SupersetDashboardInfoProvider;
use Bitrix\Bitrix24\Feature;
use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Buttons;
use Bitrix\UI\Toolbar\Facade\Toolbar;

Loader::includeModule('biconnector');

class ApacheSupersetDashboardEditComponent
	extends CBitrixComponent
	implements Main\Engine\Contract\Controllerable, Main\Errorable
{
	use Main\ErrorableImplementation;

	private const DASHBOARD_TITLE_MAX_LENGTH = 128;

	private ?Dashboard $dashboard = null;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->errorCollection = new Main\ErrorCollection();
	}

	public function onPrepareComponentParams($arParams)
	{
		$arParams['DASHBOARD_ID'] = (int)($arParams['DASHBOARD_ID'] ?? 0);

		$groupIds = $arParams['GROUP_IDS'] ?? [];
		if (!is_array($groupIds))
		{
			$groupIds = [$groupIds];
		}
		$arParams['GROUP_IDS'] = $groupIds;

		return parent::onPrepareComponentParams($arParams);
	}

	public function configureActions(): array
	{
		return [
			'save' => [
				'-prefilters' => [
					Main\Engine\ActionFilter\HttpMethod::class,
				],
				'+prefilters' => [
					new Main\Engine\ActionFilter\HttpMethod([
						Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
					]),
				],
			],
		];
	}

	protected function listKeysSignedParameters()
	{
		return ['DASHBOARD_ID'];
	}

	public function executeComponent(): void
	{
		$this->initDashboard();

		$checkAccessResult = $this->checkAccess();
		if (!$checkAccessResult->isSuccess())
		{
			$this->arResult['ERROR_MESSAGES'] = $checkAccessResult->getErrorMessages();
			$this->includeComponentTemplate();

			return;
		}

		if ($this->isEditMode())
		{
			$this->arResult['TITLE'] = $this->dashboard->getTitle();
		}
		else
		{
			$this->arResult['TITLE'] = Loc::getMessage('DASHBOARD_EDIT_FORM_TITLE');
		}

		Toolbar::addButton(
			new Buttons\Button(
				[
					'color' => Buttons\Color::LIGHT_BORDER,
					'size' => Buttons\Size::MEDIUM,
					'click' => new Buttons\JsCode(
						"top.BX.Helper.show('redirect=detail&code=28376530');",
					),
					'text' => Loc::getMessage('DASHBOARD_EDIT_FORM_HELP'),
					'dataset' => [
						'toolbar-collapsed-icon' => Buttons\Icon::INFO,
					],
				],
			),
		);

		$this->prepareSettings();
		$this->includeComponentTemplate();
	}

	private function prepareSettings(): void
	{
		$defaultValues = $this->getDefaultValues();

		$this->arResult['SETTINGS'] = [
			'nodeId' => 'dashboard-edit-form',
			'componentName' => $this->getName(),
			'signedParameters' => $this->getSignedParameters(),
			'dashboardId' => (int)$this->arParams['DASHBOARD_ID'],
			'isEditMode' => $this->isEditMode(),
			'isAllowedClearGroups' => $this->isAllowedClearGroups(),
			'defaultValues' => $defaultValues,
			'periodList' => $this->getPeriodList(),
			'paramList' => $this->getParamList(),
			'requiredParamList' => $this->getRequiredParamList(),
			'groupIds' => $defaultValues['groups'],
			'activeUrlParamsSelector' => SupersetInitializer::isSupersetReady(),
		];
	}

	private function getDefaultValues(): array
	{
		if ($this->isEditMode() && $this->dashboard !== null)
		{
			$dashboardInfoValues = $this->getDashboardInfoDefaultValues($this->dashboard->getId());
			$editableGroups = $this->getEditableGroupIds();
			$groupIds = array_values(array_intersect(
				$this->getDashboardGroupIds($this->dashboard->getId()),
				$editableGroups,
			));
			$periodValues = $this->getDashboardPeriodDefaultValues();

			return [
				'title' => $this->dashboard->getTitle(),
				'description' => $dashboardInfoValues['description'],
				'coverImageId' => $dashboardInfoValues['coverImageId'],
				'coverImageSrc' => $dashboardInfoValues['coverImageSrc'],
				'galleryImageIds' => $dashboardInfoValues['galleryImageIds'],
				'galleryImages' => $dashboardInfoValues['galleryImages'],
				'groups' => $groupIds,
				'scopes' => ScopeService::getInstance()->getDashboardScopes($this->dashboard->getId()),
				'params' => $this->appendRequiredParams($this->getDashboardParamCodes($this->dashboard->getId())),
				'filterPeriod' => $periodValues['filterPeriod'],
				'dateFilterStart' => $periodValues['dateFilterStart'],
				'dateFilterEnd' => $periodValues['dateFilterEnd'],
			];
		}
		$periodValues = $this->getNewDashboardPeriodDefaultValues();

		return [
			'title' => $this->getDefaultDashboardTitle(),
			'description' => '',
			'coverImageId' => null,
			'coverImageSrc' => '',
			'galleryImageIds' => [],
			'galleryImages' => [],
			'groups' => $this->getInitialGroupIds(),
			'scopes' => [],
			'params' => $this->getRequiredParamCodes(),
			'filterPeriod' => $periodValues['filterPeriod'],
			'dateFilterStart' => $periodValues['dateFilterStart'],
			'dateFilterEnd' => $periodValues['dateFilterEnd'],
		];
	}

	private function getDashboardPeriodDefaultValues(): array
	{
		$filterPeriod = $this->dashboard?->getOrmObject()->getFilterPeriod();
		if (!is_string($filterPeriod) || $filterPeriod === '')
		{
			$filterPeriod = EmbeddedFilter\DateTime::PERIOD_DEFAULT;
		}

		$dateFilterStart = $this->dashboard?->getOrmObject()->getDateFilterStart() ?? EmbeddedFilter\DateTime::getDefaultDateStart();
		$dateFilterEnd = $this->dashboard?->getOrmObject()->getDateFilterEnd() ?? EmbeddedFilter\DateTime::getDefaultDateEnd();

		return [
			'filterPeriod' => $filterPeriod,
			'dateFilterStart' => $dateFilterStart->toString(),
			'dateFilterEnd' => $dateFilterEnd->toString(),
		];
	}

	private function getNewDashboardPeriodDefaultValues(): array
	{
		return [
			'filterPeriod' => EmbeddedFilter\DateTime::PERIOD_DEFAULT,
			'dateFilterStart' => EmbeddedFilter\DateTime::getDefaultDateStart()->toString(),
			'dateFilterEnd' => EmbeddedFilter\DateTime::getDefaultDateEnd()->toString(),
		];
	}

	private function getDashboardInfoDefaultValues(int $dashboardId): array
	{
		$result = [
			'description' => '',
			'coverImageId' => null,
			'coverImageSrc' => '',
			'galleryImageIds' => [],
			'galleryImages' => [],
		];

		try
		{
			$dashboardInfo = $this->getDashboardInfoProvider()->getByDashboardId($dashboardId);
		}
		catch (\Throwable)
		{
			return $result;
		}

		if ($dashboardInfo === null)
		{
			return $result;
		}

		$result['description'] = (string)($dashboardInfo->getDescription() ?? '');

		$imageId = (int)($dashboardInfo->getImageId() ?? 0);
		if ($imageId > 0)
		{
			$result['coverImageId'] = $imageId;
			$coverImageSrc = (string)\CFile::GetPath($imageId);
			if ($coverImageSrc !== '')
			{
				$result['coverImageSrc'] = $coverImageSrc;
			}
		}

		try
		{
			$galleryCollection = $this->getDashboardInfoGalleryProvider()->getByDashboardInfoId((int)$dashboardInfo->getId());
			foreach ($galleryCollection as $galleryItem)
			{
				$galleryImageId = (int)$galleryItem->getImageId();
				if ($galleryImageId > 0)
				{
					$result['galleryImageIds'][] = $galleryImageId;
					$fileData = \CFile::GetFileArray($galleryImageId);
					if (is_array($fileData))
					{
						$galleryFile = [
							'serverFileId' => $galleryImageId,
							'name' => (string)($fileData['ORIGINAL_NAME'] ?? $fileData['FILE_NAME'] ?? ''),
							'type' => (string)($fileData['CONTENT_TYPE'] ?? ''),
							'size' => (int)($fileData['FILE_SIZE'] ?? 0),
							'serverPreviewUrl' => (string)($fileData['SRC'] ?? ''),
							'downloadUrl' => (string)($fileData['SRC'] ?? ''),
							'customData' => [
								'realFileId' => $galleryImageId,
							],
						];

						$width = (int)($fileData['WIDTH'] ?? 0);
						if ($width > 0)
						{
							$galleryFile['width'] = $width;
							$galleryFile['serverPreviewWidth'] = $width;
						}

						$height = (int)($fileData['HEIGHT'] ?? 0);
						if ($height > 0)
						{
							$galleryFile['height'] = $height;
							$galleryFile['serverPreviewHeight'] = $height;
						}

						$result['galleryImages'][] = $galleryFile;
					}
				}
			}
		}
		catch (\Throwable)
		{
		}

		return $result;
	}

	private function getPeriodList(): array
	{
		$periods = [
			EmbeddedFilter\DateTime::PERIOD_LAST_7,
			EmbeddedFilter\DateTime::PERIOD_LAST_30,
			EmbeddedFilter\DateTime::PERIOD_LAST_90,
			EmbeddedFilter\DateTime::PERIOD_LAST_180,
			EmbeddedFilter\DateTime::PERIOD_LAST_365,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_WEEK,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_MONTH,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_YEAR,
			EmbeddedFilter\DateTime::PERIOD_RANGE,
			EmbeddedFilter\DateTime::PERIOD_NONE,
		];

		$items = [];
		foreach ($periods as $period)
		{
			$items[] = [
				'value' => $period,
				'name' => EmbeddedFilter\DateTime::getPeriodName($period),
			];
		}

		$defaultPeriodLabel = (new DefaultPeriodLabelBuilder())->build();
		$items[] = [
			'value' => EmbeddedFilter\DateTime::PERIOD_DEFAULT,
			'name' => $defaultPeriodLabel['fullText'],
			'isDefault' => true,
			'prefixText' => $defaultPeriodLabel['prefixText'],
			'valueText' => $defaultPeriodLabel['valueText'],
			'suffixText' => $defaultPeriodLabel['suffixText'],
		];

		return $items;
	}

	private function getDefaultDashboardTitle(): string
	{
		$name = Loc::getMessage('DASHBOARD_EDIT_FORM_DEFAULT_TITLE');

		$dashboard = SupersetDashboardTable::getRow([
			'select' => ['TITLE'],
			'filter' => ['%TITLE' => $name],
			'order' => ['ID' => 'DESC'],
		]);
		if ($dashboard)
		{
			$currentTitle = $dashboard['TITLE'];
			preg_match_all('/\d+/', $currentTitle, $matches);
			$number = (int)($matches[0][0] ?? 0) + 1;
			$name = Loc::getMessage('DASHBOARD_EDIT_FORM_DEFAULT_TITLE_NUMBER', ['#NUMBER#' => $number]);
		}

		return $name;
	}

	private function getParamList(): array
	{
		return UrlParameter\ScopeMap::getParamList();
	}

	private function getRequiredParamList(): array
	{
		return UrlParameter\ScopeMap::getRequiredParamList();
	}

	private function getRequiredParamCodes(): array
	{
		return array_keys($this->getRequiredParamList());
	}

	private function appendRequiredParams(array $params): array
	{
		$params = array_values(array_filter($params, static fn ($param) => is_string($param) && $param !== ''));

		return array_values(array_unique(array_merge(
			$params,
			$this->getRequiredParamCodes(),
		)));
	}

	private function getInitialGroupIds(): array
	{
		$groupIds = $this->arParams['GROUP_IDS'] ?? [];
		if (empty($groupIds))
		{
			return [];
		}

		$groupIds = array_map('intval', $groupIds);
		$editableGroups = $this->getEditableGroupIds();

		return array_values(array_intersect(
			$groupIds,
			$editableGroups,
		));
	}

	private function getEditableGroupIds(): array
	{
		$groupIds = AccessController::getCurrent()->getAllowedGroupValue(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);

		return array_map('intval', $groupIds);
	}

	private function isAllowedClearGroups(): bool
	{
		return
			$this->isEditMode()
			&& AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SETTINGS_EDIT_RIGHTS)
		;
	}

	private function checkAccess(): Main\Result
	{
		$result = new Main\Result();

		if (Loader::includeModule('bitrix24') && !Feature::isFeatureEnabled('bi_constructor'))
		{
			$result->addError(new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_OPEN_BIC_UNAVAILABLE_ERROR')));

			return $result;
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			$result->addError(new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_OPEN_BIC_ACCESS_ERROR')));

			return $result;
		}

		if ($this->isEditMode())
		{
			if ($this->dashboard === null)
			{
				$result->addError(new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_DASHBOARD_NOT_FOUND')));

				return $result;
			}

			if ($this->dashboard->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
			{
				$result->addError(new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_ONLY_CUSTOM_EDIT_ERROR')));

				return $result;
			}

			if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $this->dashboard))
			{
				$result->addError(new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_OPEN_EDIT_ACCESS_ERROR')));

				return $result;
			}
		}
		elseif (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			$result->addError(new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_OPEN_ACCESS_ERROR')));

			return $result;
		}

		return $result;
	}

	public function saveAction(array $data, Main\Engine\CurrentUser $user): ?array
	{
		$this->initDashboard();

		$checkAccessResult = $this->checkAccess();
		if (!$checkAccessResult->isSuccess())
		{
			$this->errorCollection->add($checkAccessResult->getErrors());

			return null;
		}

		$saveData = $this->prepareSaveData($data);
		if ($saveData === null)
		{
			return null;
		}

		$imageValidationData = $this->validateAndPrepareImageData($saveData);
		if ($imageValidationData === null)
		{
			return null;
		}

		return $this->isEditMode()
			? $this->updateDashboard($saveData, $imageValidationData)
			: $this->createDashboard($saveData, $user, $imageValidationData)
		;
	}

	private function createDashboard(
		DashboardSaveData $saveData,
		Main\Engine\CurrentUser $user,
		array $imageValidationData,
	): ?array
	{
		$dashboard = SupersetDashboardTable::createObject();
		$dashboard
			->setTitle($saveData->title)
			->setType(SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
			->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
			->setCreatedById((int)$user->getId())
		;
		$this->applyPeriodData($dashboard, $saveData->getPeriodData());

		$isSaved = $this->executeInTransaction(
			function () use ($dashboard, $saveData, $imageValidationData): bool {
				$saveResult = $dashboard->save();
				if (!$saveResult->isSuccess())
				{
					return false;
				}

				if (!$this->saveDashboardOptions($dashboard, $saveData))
				{
					return false;
				}

				if (!$this->saveDashboardInfo(
					$dashboard->getId(),
					$saveData->description,
					$imageValidationData['coverImageId'],
					$imageValidationData['galleryImageIds']
				))
				{
					return false;
				}

				if (!$this->makePendingFilesPersistent($imageValidationData['confirmedTempFileIds']))
				{
					return false;
				}

				return true;
			},
			'DASHBOARD_EDIT_FORM_ERROR',
		);
		if (!$isSaved)
		{
			return null;
		}

		$this->createDashboardInSuperset($dashboard, $saveData);

		return $this->buildDashboardResponse($dashboard->getId());
	}

	private function updateDashboard(DashboardSaveData $saveData, array $imageValidationData): ?array
	{
		if ($this->dashboard === null)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_DASHBOARD_NOT_FOUND'));

			return null;
		}

		$dashboardObject = $this->dashboard->getOrmObject();
		$fileCleanupService = $this->getFileCleanupService();
		$currentImageIds = $imageValidationData['existingImageIds'];
		$newImageIds = $this->prepareDashboardInfoImageIds(
			$imageValidationData['coverImageId'],
			$imageValidationData['galleryImageIds'],
		);
		$imageIdsToDelete = $fileCleanupService->getRemovedImageIds($currentImageIds, $newImageIds);
		if ($dashboardObject->getTitle() !== $saveData->title)
		{
			$dashboardObject->setTitle($saveData->title);
		}

		$this->applyPeriodData($dashboardObject, $saveData->getPeriodData());

		$isUpdated = $this->executeInTransaction(
			function () use ($dashboardObject, $saveData, $imageValidationData): bool {
				$saveResult = $dashboardObject->save();
				if (!$saveResult->isSuccess())
				{
					return false;
				}

				if (!$this->saveDashboardOptions($dashboardObject, $saveData, true))
				{
					return false;
				}

				if (!$this->saveDashboardInfo(
					$dashboardObject->getId(),
					$saveData->description,
					$imageValidationData['coverImageId'],
					$imageValidationData['galleryImageIds']
				))
				{
					return false;
				}

				if (!$this->makePendingFilesPersistent($imageValidationData['confirmedTempFileIds']))
				{
					return false;
				}

				return true;
			},
			'DASHBOARD_EDIT_FORM_UPDATE_ERROR',
		);
		if (!$isUpdated)
		{
			return null;
		}

		$fileCleanupService->deleteFiles($imageIdsToDelete);
		$this->updateDashboardInSuperset($dashboardObject, $saveData->title);

		return $this->buildDashboardResponse($dashboardObject->getId());
	}

	private function createDashboardInSuperset(SupersetDashboard $dashboard, DashboardSaveData $saveData): void
	{
		if (!SupersetInitializer::isSupersetReady())
		{
			return;
		}

		$response = Integrator::getInstance()->createEmptyDashboard([
			'name' => $saveData->title,
			'json_metadata' => $this->getJsonMetadata($saveData),
		]);

		if ($response->hasErrors())
		{
			return;
		}

		$externalId = (int)($response->getData()['body']['id'] ?? 0);
		if ($externalId <= 0)
		{
			return;
		}

		$dashboard->setExternalId($externalId);
		$dashboard->save();
	}

	private function updateDashboardInSuperset(SupersetDashboard $dashboard, string $title): void
	{
		if (!SupersetInitializer::isSupersetReady())
		{
			return;
		}

		$externalId = (int)$dashboard->getExternalId();
		if ($externalId <= 0)
		{
			return;
		}

		$response = Integrator::getInstance()->updateDashboard($externalId, ['dashboard_title' => $title]);
		if ($response->hasErrors())
		{
			return;
		}

		$actualTitle = $title;
		$changedFields = $response->getData();
		if (is_string($changedFields['dashboard_title'] ?? null))
		{
			$actualTitle = $changedFields['dashboard_title'];
		}

		if ($dashboard->getTitle() !== $actualTitle)
		{
			$dashboard->setTitle($actualTitle);
			$dashboard->save();
		}
	}

	private function executeInTransaction(callable $callback, string $errorCode): bool
	{
		$connection = SupersetDashboardTable::getEntity()->getConnection();
		$isTransactionStarted = false;

		try
		{
			$connection->startTransaction();
			$isTransactionStarted = true;

			if (!$callback())
			{
				$connection->rollbackTransaction();
				if (count($this->errorCollection) === 0)
				{
					$this->errorCollection[] = new Error(Loc::getMessage($errorCode));
				}

				return false;
			}

			$connection->commitTransaction();

			return true;
		}
		catch (\Throwable)
		{
			if ($isTransactionStarted)
			{
				try
				{
					$connection->rollbackTransaction();
				}
				catch (\Throwable)
				{
				}
			}

			if (count($this->errorCollection) === 0)
			{
				$this->errorCollection[] = new Error(Loc::getMessage($errorCode));
			}

			return false;
		}
	}

	private function saveDashboardOptions(
		SupersetDashboard $dashboard,
		DashboardSaveData $saveData,
		bool $preserveNotEditableGroups = false
	): bool
	{
		$editableGroups = $this->getEditableGroupIds();
		$groups = array_values(array_intersect($saveData->groups, $editableGroups));

		if ($preserveNotEditableGroups)
		{
			$savedGroupIds = $this->getDashboardGroupIds($dashboard->getId());
			$notEditableGroups = array_values(array_diff($savedGroupIds, $editableGroups));
			$groups = array_values(array_unique(array_merge($groups, $notEditableGroups)));
		}

		if (empty($groups))
		{
			$messageCode = $this->isEditMode() && $this->isAllowedClearGroups()
				? null
				: (
					$this->isEditMode()
						? 'DASHBOARD_EDIT_FORM_EMPTY_DASHBOARD_GROUP_ERROR'
						: 'DASHBOARD_EDIT_FORM_EMPTY_CREATE_DASHBOARD_GROUP_ERROR'
				)
			;

			if ($messageCode !== null)
			{
				$this->errorCollection[] = new Error(Loc::getMessage($messageCode));

				return false;
			}
		}

		$saveGroupResult = DashboardGroupService::saveDashboardGroupBindings($dashboard->getId(), $groups);
		$saveScopesResult = ScopeService::getInstance()->saveDashboardScopes($dashboard->getId(), $saveData->scopes);
		$saveParamsResult = (new UrlParameter\Service($dashboard))->saveDashboardParams($saveData->params, $saveData->scopes);

		if (
			!$saveGroupResult->isSuccess()
			|| !$saveScopesResult->isSuccess()
			|| !$saveParamsResult->isSuccess()
		)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_SAVE_PARAMS_ERROR'));

			return false;
		}

		return true;
	}

	private function saveDashboardInfo(
		int $dashboardId,
		?string $description = null,
		?int $coverImageId = null,
		array $galleryImageIds = [],
	): bool
	{
		try
		{
			$dashboardInfo = $this->getDashboardInfoProvider()->getByDashboardId($dashboardId);
		}
		catch (\Throwable)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_SAVE_DESCRIPTION_ERROR'));

			return false;
		}

		if ($dashboardInfo !== null && (int)$dashboardInfo->getId() > 0)
		{
			$commandResult = (new UpdateSupersetDashboardInfoCommand(
				id: (int)$dashboardInfo->getId(),
				publishedById: $dashboardInfo->getPublishedById(),
				publishedDate: $dashboardInfo->getPublishedDate(),
				updatedById: $dashboardInfo->getUpdatedById(),
				updatedDate: $dashboardInfo->getUpdatedDate(),
				description: $description,
				imageId: $coverImageId,
			))->run();
		}
		else
		{
			$commandResult = (new AddSupersetDashboardInfoCommand(
				dashboardId: $dashboardId,
				description: $description,
				imageId: $coverImageId,
			))->run();
		}

		if (!$commandResult->isSuccess())
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_SAVE_DESCRIPTION_ERROR'));

			return false;
		}

		$dashboardInfoEntity = $commandResult->getDashboardInfo();
		$dashboardInfoId = (int)($dashboardInfoEntity?->getId() ?? 0);
		if ($dashboardInfoId <= 0)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_SAVE_DESCRIPTION_ERROR'));

			return false;
		}

		if (!$this->saveDashboardInfoGallery($dashboardInfoId, $galleryImageIds))
		{
			return false;
		}

		return true;
	}

	private function saveDashboardInfoGallery(int $dashboardInfoId, array $galleryImageIds): bool
	{
		$galleryImageIds = $this->normalizeImageIds($galleryImageIds);
		$desiredImageIdMap = array_flip($galleryImageIds);

		try
		{
			$galleryCollection = $this->getDashboardInfoGalleryProvider()->getByDashboardInfoId($dashboardInfoId);
		}
		catch (\Throwable)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_SAVE_GALLERY_ERROR'));

			return false;
		}

		$currentItemsByImageId = [];
		foreach ($galleryCollection as $galleryItem)
		{
			$imageId = (int)$galleryItem->getImageId();
			if ($imageId > 0)
			{
				$currentItemsByImageId[$imageId] = $galleryItem;
			}
		}

		foreach ($currentItemsByImageId as $imageId => $galleryItem)
		{
			if (isset($desiredImageIdMap[$imageId]))
			{
				continue;
			}

			$deleteResult = (new DeleteSupersetDashboardInfoGalleryCommand(
				id: (int)$galleryItem->getId(),
			))->run();
			if (!$deleteResult->isSuccess())
			{
				$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_SAVE_GALLERY_ERROR'));

				return false;
			}
		}

		foreach ($galleryImageIds as $index => $imageId)
		{
			$sort = ($index + 1) * 100;
			if (isset($currentItemsByImageId[$imageId]))
			{
				$item = $currentItemsByImageId[$imageId];
				$saveResult = (new UpdateSupersetDashboardInfoGalleryCommand(
					id: (int)$item->getId(),
					imageId: $imageId,
					sort: $sort,
				))->run();
			}
			else
			{
				$saveResult = (new AddSupersetDashboardInfoGalleryCommand(
					dashboardInfoId: $dashboardInfoId,
					imageId: $imageId,
					sort: $sort,
				))->run();
			}

			if (!$saveResult->isSuccess())
			{
				$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_SAVE_GALLERY_ERROR'));

				return false;
			}
		}

		return true;
	}

	private function makePendingFilesPersistent(array $tempFileIds): bool
	{
		$tempFileIds = $this->normalizeTempFileIds($tempFileIds);
		if (empty($tempFileIds))
		{
			return true;
		}

		try
		{
			$pendingFiles = $this->createDashboardInfoUploader()->getUploader()->getPendingFiles($tempFileIds);
			$pendingFiles->makePersistent();
		}
		catch (\Throwable)
		{
			return false;
		}

		return true;
	}

	private function validateAndPrepareImageData(DashboardSaveData $saveData): ?array
	{
		$coverImageId = $saveData->getCoverImageId();
		$galleryImageIds = $this->normalizeImageIds($saveData->getGalleryImageIds());

		$coverTempFileId = $saveData->getCoverImageTempFileId();
		$galleryTempFileIds = $this->normalizeTempFileIds($saveData->getGalleryImageTempFileIds());
		$requestedTempFileIds = $galleryTempFileIds;
		if ($coverTempFileId !== null)
		{
			$requestedTempFileIds[] = $coverTempFileId;
		}

		$pendingImageIdMap = $this->getPendingImageIdMapByTempFileIds($requestedTempFileIds);
		if ($pendingImageIdMap === null)
		{
			return null;
		}

		$existingImageIds = $this->getCurrentDashboardImageIds();
		$allowedImageIdMap = array_flip($this->normalizeImageIds(array_merge(
			$existingImageIds,
			array_values($pendingImageIdMap),
		)));
		if (!$this->areSelectedImageIdsAllowed($coverImageId, $galleryImageIds, $allowedImageIdMap))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_INVALID_IMAGE_ERROR'));

			return null;
		}

		$confirmedTempFileIds = [];
		if (
			$coverTempFileId !== null
			&& $coverImageId !== null
			&& ($pendingImageIdMap[$coverTempFileId] ?? 0) === $coverImageId
		)
		{
			$confirmedTempFileIds[] = $coverTempFileId;
		}

		$galleryImageIdMap = array_flip($galleryImageIds);
		foreach ($galleryTempFileIds as $tempFileId)
		{
			$pendingImageId = (int)($pendingImageIdMap[$tempFileId] ?? 0);
			if ($pendingImageId > 0 && isset($galleryImageIdMap[$pendingImageId]))
			{
				$confirmedTempFileIds[] = $tempFileId;
			}
		}

		return [
			'coverImageId' => $coverImageId,
			'galleryImageIds' => $galleryImageIds,
			'existingImageIds' => $existingImageIds,
			'confirmedTempFileIds' => $this->normalizeTempFileIds($confirmedTempFileIds),
		];
	}

	private function getCurrentDashboardImageIds(): array
	{
		if (!$this->isEditMode() || $this->dashboard === null)
		{
			return [];
		}

		return $this->normalizeImageIds(
			$this->getFileCleanupService()->collectImageIdsByDashboardId($this->dashboard->getId())
		);
	}

	private function getPendingImageIdMapByTempFileIds(array $tempFileIds): ?array
	{
		$tempFileIds = $this->normalizeTempFileIds($tempFileIds);
		if (empty($tempFileIds))
		{
			return [];
		}

		try
		{
			$pendingFiles = $this->createDashboardInfoUploader()->getUploader()->getPendingFiles($tempFileIds);
		}
		catch (\Throwable)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_INVALID_IMAGE_ERROR'));

			return null;
		}

		$pendingImageIdMap = [];
		foreach ($pendingFiles->getAll() as $tempFileId => $pendingFile)
		{
			if (!$pendingFile->isValid())
			{
				continue;
			}

			$pendingImageId = (int)$pendingFile->getFileId();
			if ($pendingImageId > 0)
			{
				$pendingImageIdMap[$tempFileId] = $pendingImageId;
			}
		}

		return $pendingImageIdMap;
	}

	private function areSelectedImageIdsAllowed(?int $coverImageId, array $galleryImageIds, array $allowedImageIdMap): bool
	{
		$selectedImageIds = $galleryImageIds;
		if ($coverImageId !== null && $coverImageId > 0)
		{
			$selectedImageIds[] = $coverImageId;
		}

		foreach ($selectedImageIds as $selectedImageId)
		{
			if (!isset($allowedImageIdMap[$selectedImageId]))
			{
				return false;
			}
		}

		return true;
	}

	private function createDashboardInfoUploader(): FileUploader
	{
		return new FileUploader(
			new DashboardInfoUploaderController([
				'dashboardId' => (int)($this->arParams['DASHBOARD_ID'] ?? 0),
			])
		);
	}

	private function preparePeriodData(array $data): ?array
	{
		$filterPeriod = $data['filterPeriod'] ?? EmbeddedFilter\DateTime::PERIOD_DEFAULT;
		if (!is_string($filterPeriod))
		{
			$filterPeriod = EmbeddedFilter\DateTime::PERIOD_DEFAULT;
		}

		if ($filterPeriod === EmbeddedFilter\DateTime::PERIOD_DEFAULT)
		{
			return [
				'FILTER_PERIOD' => null,
				'DATE_FILTER_START' => null,
				'DATE_FILTER_END' => null,
				'INCLUDE_LAST_FILTER_DATE' => null,
			];
		}

		if ($filterPeriod === EmbeddedFilter\DateTime::PERIOD_NONE)
		{
			return [
				'FILTER_PERIOD' => EmbeddedFilter\DateTime::PERIOD_NONE,
				'DATE_FILTER_START' => null,
				'DATE_FILTER_END' => null,
				'INCLUDE_LAST_FILTER_DATE' => null,
			];
		}

		if ($filterPeriod === EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			try
			{
				$dateFilterStart = new Main\Type\Date((string)($data['dateFilterStart'] ?? ''));
				$dateFilterEnd = new Main\Type\Date((string)($data['dateFilterEnd'] ?? ''));
			}
			catch (Main\ObjectException)
			{
				$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_INVALID_RANGE_ERROR'));

				return null;
			}

			if ($dateFilterStart->getTimestamp() > $dateFilterEnd->getTimestamp())
			{
				$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_INVALID_RANGE_ERROR'));

				return null;
			}

			return [
				'FILTER_PERIOD' => EmbeddedFilter\DateTime::PERIOD_RANGE,
				'DATE_FILTER_START' => $dateFilterStart,
				'DATE_FILTER_END' => $dateFilterEnd,
				'INCLUDE_LAST_FILTER_DATE' => 'Y',
			];
		}

		$period = EmbeddedFilter\DateTime::getDefaultPeriod();
		if (EmbeddedFilter\DateTime::isAvailablePeriod($filterPeriod))
		{
			$period = $filterPeriod;
		}

		return [
			'FILTER_PERIOD' => $period,
			'DATE_FILTER_START' => null,
			'DATE_FILTER_END' => null,
			'INCLUDE_LAST_FILTER_DATE' => null,
		];
	}

	private function applyPeriodData(SupersetDashboard $dashboard, array $periodData): void
	{
		$dashboard
			->setFilterPeriod($periodData['FILTER_PERIOD'])
			->setDateFilterStart($periodData['DATE_FILTER_START'])
			->setDateFilterEnd($periodData['DATE_FILTER_END'])
			->setIncludeLastFilterDate($periodData['INCLUDE_LAST_FILTER_DATE'])
		;
	}

	private function buildDashboardResponse(int $dashboardId): ?array
	{
		$superset = new SupersetController(Integrator::getInstance());
		$dashboard = $superset->getDashboardRepository()->getById($dashboardId, true);
		if ($dashboard === null)
		{
			$messageCode = $this->isEditMode()
				? 'DASHBOARD_EDIT_FORM_UPDATE_ERROR'
				: 'DASHBOARD_EDIT_FORM_ERROR'
			;
			$this->errorCollection[] = new Error(Loc::getMessage($messageCode));

			return null;
		}

		return [
			'dashboard' => [
				'id' => $dashboard->getId(),
				'title' => $dashboard->getTitle(),
				'detailUrl' => $dashboard->getOrmObject()->getDetailUrl()->getUri(),
			],
		];
	}

	private function initDashboard(): void
	{
		$this->dashboard = null;
		if (!$this->isEditMode())
		{
			return;
		}

		$superset = new SupersetController(Integrator::getInstance());
		$this->dashboard = $superset->getDashboardRepository()->getById((int)$this->arParams['DASHBOARD_ID']);
	}

	private function getDashboardGroupIds(int $dashboardId): array
	{
		$bindings = SupersetDashboardGroupBindingTable::getList([
			'select' => ['GROUP_ID'],
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
		])->fetchAll();

		return array_map('intval', array_column($bindings, 'GROUP_ID'));
	}

	private function getDashboardParamCodes(int $dashboardId): array
	{
		$params = SupersetDashboardUrlParameterTable::getList([
			'select' => ['CODE'],
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
		])->fetchAll();

		return array_values(array_filter(array_column($params, 'CODE'), static fn ($code) => is_string($code)));
	}

	private function getDashboardInfoProvider(): SupersetDashboardInfoProvider
	{
		$mapper = new SupersetDashboardInfoMapper();
		$repository = new SupersetDashboardInfoRepository($mapper);

		return new SupersetDashboardInfoProvider($repository);
	}

	private function getDashboardInfoGalleryProvider(): SupersetDashboardInfoGalleryProvider
	{
		$mapper = new SupersetDashboardInfoGalleryMapper();
		$repository = new SupersetDashboardInfoGalleryRepository($mapper);

		return new SupersetDashboardInfoGalleryProvider($repository);
	}

	private function prepareSaveData(array $data): ?DashboardSaveData
	{
		$title = trim((string)($data['title'] ?? ''));
		if ($title === '')
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_EMPTY_TITLE_ERROR'));

			return null;
		}

		if (mb_strlen($title) > self::DASHBOARD_TITLE_MAX_LENGTH)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DASHBOARD_EDIT_FORM_TITLE_TOO_LONG_ERROR'));

			return null;
		}

		$periodData = $this->preparePeriodData(
			is_array($data['period'] ?? null) ? $data['period'] : []
		);
		if ($periodData === null)
		{
			return null;
		}

		$coverImageData = $this->prepareCoverImageData($data['coverImage'] ?? []);
		$galleryImageData = $this->prepareGalleryImageData($data['galleryImage'] ?? []);

		return new DashboardSaveData(
			title: $title,
			description: $this->normalizeDescription($data['description'] ?? null),
			groups: $this->normalizeGroupIds($data['groups'] ?? []),
			scopes: $this->normalizeStringList($data['scopes'] ?? []),
			params: $this->appendRequiredParams($this->normalizeStringList($data['params'] ?? [])),
			coverImage: $coverImageData,
			galleryImage: $galleryImageData,
			period: $periodData,
		);
	}

	private function prepareCoverImageData(mixed $coverImageData): array
	{
		$coverImageData = is_array($coverImageData) ? $coverImageData : [];

		return [
			'id' => $this->normalizeCoverImageId($coverImageData['id'] ?? null),
			'tempFileId' => $this->normalizeTempFileId($coverImageData['tempFileId'] ?? null),
		];
	}

	private function prepareGalleryImageData(mixed $galleryImageData): array
	{
		$galleryImageData = is_array($galleryImageData) ? $galleryImageData : [];

		return [
			'ids' => $this->normalizeImageIds($galleryImageData['ids'] ?? []),
			'tempFileIds' => $this->normalizeTempFileIds($galleryImageData['tempFileIds'] ?? []),
		];
	}

	private function normalizeGroupIds(mixed $groups): array
	{
		if (!is_array($groups))
		{
			return [];
		}

		$groups = array_map('intval', $groups);

		return array_values(array_filter($groups, static fn (int $groupId): bool => $groupId > 0));
	}

	private function normalizeStringList(mixed $values): array
	{
		if (!is_array($values))
		{
			return [];
		}

		return array_values(array_filter(
			$values,
			static fn ($value): bool => is_string($value) && $value !== '',
		));
	}

	private function normalizeDescription(mixed $description): ?string
	{
		if (!is_string($description))
		{
			return null;
		}

		$description = trim($description);

		return $description !== '' ? $description : null;
	}

	private function normalizeCoverImageId(mixed $coverImageId): ?int
	{
		if (is_int($coverImageId))
		{
			return $coverImageId > 0 ? $coverImageId : null;
		}

		if (!is_string($coverImageId))
		{
			return null;
		}

		$coverImageId = trim($coverImageId);
		if ($coverImageId === '' || !preg_match('/^\d+$/', $coverImageId))
		{
			return null;
		}

		$imageId = (int)$coverImageId;

		return $imageId > 0 ? $imageId : null;
	}

	private function normalizeImageIds(mixed $imageIds): array
	{
		if (!is_array($imageIds))
		{
			return [];
		}

		$imageIds = array_map('intval', $imageIds);
		$imageIds = array_values(array_filter($imageIds, static fn (int $imageId): bool => $imageId > 0));

		return array_values(array_unique($imageIds));
	}

	private function normalizeTempFileId(mixed $tempFileId): ?string
	{
		if (!is_string($tempFileId))
		{
			return null;
		}

		$tempFileId = trim($tempFileId);
		if ($tempFileId === '' || mb_strpos($tempFileId, '.') === false)
		{
			return null;
		}

		return $tempFileId;
	}

	private function normalizeTempFileIds(mixed $tempFileIds): array
	{
		if (!is_array($tempFileIds))
		{
			return [];
		}

		$normalizedTempFileIds = [];
		foreach ($tempFileIds as $tempFileId)
		{
			$normalizedTempFileId = $this->normalizeTempFileId($tempFileId);
			if ($normalizedTempFileId !== null)
			{
				$normalizedTempFileIds[] = $normalizedTempFileId;
			}
		}

		return array_values(array_unique($normalizedTempFileIds));
	}

	private function prepareDashboardInfoImageIds(?int $coverImageId, array $galleryImageIds): array
	{
		$imageIds = $galleryImageIds;
		if ($coverImageId !== null && $coverImageId > 0)
		{
			$imageIds[] = $coverImageId;
		}

		return $this->normalizeImageIds($imageIds);
	}

	private function getFileCleanupService(): FileCleanupService
	{
		return ServiceLocator::getInstance()->get('biconnector.service.dashboardInfo.fileCleanup');
	}

	private function isEditMode(): bool
	{
		return (int)($this->arParams['DASHBOARD_ID'] ?? 0) > 0;
	}

	private function getJsonMetadata(DashboardSaveData $saveData): array
	{
		$builder = new DashboardMetadataBuilder();

		if (!empty($saveData->params))
		{
			$filterSection = new NativeFilterConfigurationSection($saveData->params);
			$builder->addSection($filterSection);
		}

		return $builder->build();
	}
}
