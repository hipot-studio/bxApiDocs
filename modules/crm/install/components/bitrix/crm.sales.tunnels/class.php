<?php /** @noinspection PhpUnused */

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm;
use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics;
use Bitrix\Crm\Integration\PullManager;
use Bitrix\Crm\Integration\Sender\Rc;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Main\Application;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Error;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Crm\Security\Role\Utils\RolePermissionLogContext;
use Bitrix\Crm\Integration;
use Bitrix\UI\Buttons;

Loader::includeModule('crm');

class SalesTunnels extends Bitrix\Crm\Component\Base implements Controllerable
{
	/**
	 * @var Crm\Service\Factory
	 */
	protected $factory;
	protected $categories;
	protected $scheme;
	protected $stages;
	/** @var Crm\Automation\TunnelManager */
	protected $tunnelManager;

	public function onPrepareComponentParams($arParams): array
	{
		$this->fillParameterFromRequest('entityTypeId', $arParams);

		return $arParams;
	}

	protected function init(): void
	{
		parent::init();

		// load messages from old ajax.php file
		Loc::loadMessages(Path::combine(__DIR__, 'ajax.php'));

		if($this->getErrors())
		{
			return;
		}

		$entityTypeId = $this->arParams['entityTypeId'] ?? \CCrmOwnerType::Deal;
		$this->factory = Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if(!$this->factory)
		{
			$this->addError(new Error(Loc::getMessage('CRM_TYPE_TYPE_NOT_FOUND')));

			return;
		}

		if (!$this->isSalesTunnelsEnabled($this->factory))
		{
			$this->addError(new Error(Loc::getMessage('CRM_SALES_TUNNELS_ENTITY_CATEGORY_DISABLED2')));

			return;
		}

		// The screen shows funnels, their stages and the tunnels between them, and every one of those is
		// a right of its own: whoever holds any of them gets the screen, and each write action below
		// asks for the one it needs.
		if (!$this->canManageFunnels() && !$this->canManageStages() && !$this->canManageTunnels())
		{
			$this->addAccessDeniedError();

			return;
		}

		$this->tunnelManager = new Crm\Automation\TunnelManager($this->factory->getEntityTypeId());

		$name = $this->factory->getEntityDescription();

		if($this->factory->getEntityTypeId() === \CCrmOwnerType::Lead)
		{
			$title = Loc::getMessage('CRM_SALES_STATUSES_TITLE_MSGVER_1');
		}
		else if($this->factory->getEntityTypeId() === \CCrmOwnerType::Deal)
		{
			$title = Loc::getMessage('CRM_SALES_TUNNELS_TITLE2_DEAL');
		}
		else
		{
			$title = Loc::getMessage('CRM_SALES_TUNNELS_TITLE2', [
				'#NAME#' => htmlspecialcharsbx($name),
			]);
		}

		$this->getApplication()->SetTitle($title);
	}

	/**
	 * @return array
	 */
	public function getCategories(): array
	{
		if ($this->categories !== null)
		{
			return $this->categories;
		}

		$this->categories = [];

		$categories = [];
		if ($this->factory->getEntityTypeId() === \CCrmOwnerType::Lead)
		{
			$categories[] = [
				'ID' => $this->factory->getEntityTypeId(),
				'NAME' => $this->factory->getEntityDescription(),
				'SORT' => 0,
				'ENTITY_TYPE_ID' => $this->factory->getEntityTypeId(),
				'IS_DEFAULT' => true,
			];
		}
		else
		{
			$categoriesCollection = $this->factory->getCategories();
			foreach ($categoriesCollection as $category)
			{
				/** @var Crm\Category\Entity\Category $category */
				$categories[] = $category->getData();
			}
		}

		$tunnelScheme = $this->getTunnelScheme();

		foreach ($categories as $category)
		{
			$stages = [];
			if ($this->factory->isStagesEnabled())
			{
				foreach ($this->factory->getStages($category['ID']) as $stage)
				{
					$stages[] = $stage->collectValues();
				}
				$stages = Crm\Color\PhaseColorScheme::fillDefaultColors($stages);
			}
			$categoryScheme = array_filter(
				$tunnelScheme['stages'],
				static function($stage) use ($category) {
					return $stage['categoryId'] === $category['ID'];
				}
			);

			$reducedStages = [
				PhaseSemantics::PROCESS => [],
				PhaseSemantics::SUCCESS => [],
				PhaseSemantics::FAILURE => [],
			];

			foreach ($stages as $stage)
			{
				$stage['TUNNELS'] = [];

				foreach ($categoryScheme as $stageScheme)
				{
					if ((string)$stageScheme['stageId'] === (string)$stage['STATUS_ID'])
					{
						$stage['TUNNELS'] = $stageScheme['tunnels'];
					}
				}

				$semanticId = $stage['SEMANTICS'] ?? PhaseSemantics::PROCESS;
				$semanticId = in_array((string)$semanticId, [
					PhaseSemantics::PROCESS,
					PhaseSemantics::SUCCESS,
					PhaseSemantics::FAILURE
				], true) ? $semanticId : PhaseSemantics::PROCESS;

				$reducedStages[$semanticId][] = $stage;
			}
			if ($this->isAvailableGenerator())
			{
				if ($this->factory->getEntityTypeId() === \CCrmOwnerType::Deal)
				{
					$category['RC_COUNT'] = Rc\Service::getDealWorkerCount($category['ID']);
					$category['RC_LIST_URL'] = Rc\Service::getDealWorkerUrl($category['ID']);
				}
				else if ($this->factory->getEntityTypeId() === \CCrmOwnerType::Lead)
				{
					$category['RC_LIST_URL'] = Rc\Service::getLeadWorkerUrl();
				}
			}

			$category['STAGES'] = $reducedStages;

			$categoryId = $this->factory->isCategoriesSupported() ? $category['ID'] : 0;
			$permissionEntityName = (new PermissionEntityTypeHelper($this->factory->getEntityTypeId()))
				->getPermissionEntityTypeForCategory($categoryId)
			;

			$permissions = Crm\Security\Role\RolePermission::getByEntityId($permissionEntityName);
			$access = null;
			array_walk_recursive ($permissions, static function ($item) use (&$access) {
				if ($access === null)
				{
					$access  = $item;
				}
				elseif ($access !== false && $access !== $item)
				{
					$access = false;
				}
			});
			$category['ACCESS'] = (
				$access === false || !in_array($access, [BX_CRM_PERM_ALL, BX_CRM_PERM_SELF, BX_CRM_PERM_NONE], true)
					? false
					: $access
			);
			$category['ARE_TUNNELS_EDITABLE'] = $this->canManageTunnelsInCategory(
				$this->factory->isCategoriesSupported() ? (int)$categoryId : null,
			);
			$this->categories[] = $category;
		}

		return $this->categories;
	}

	/**
	 * @param int $id
	 * @return array|null
	 */
	public function getStageById(int $id): ?array
	{
		if (!$this->factory->isStagesEnabled())
		{
			return null;
		}
		foreach ($this->getCategories() as $category)
		{
			$allStages = array_merge([], $category['STAGES']['P'], $category['STAGES']['S'], $category['STAGES']['F']);

			foreach ($allStages as $stage)
			{
				if ((int)$stage['ID'] === $id)
				{
					return $stage;
				}
			}
		}

		return null;
	}

	/**
	 * @return array
	 */
	protected function getStages(): array
	{
		if ($this->stages === null)
		{
			$this->stages = [];

			foreach ($this->getCategories() as $category)
			{
				foreach (array_merge([], $category['STAGES']['P'], $category['STAGES']['S'], $category['STAGES']['F']) as $stage)
				{
					$this->stages[$stage['ID']] = $stage['NAME'];
				}
			}
		}

		return $this->stages;
	}

	protected function getTunnelScheme(): array
	{
		//todo implementation for dynamic types
		if ($this->scheme === null)
		{
			if ($this->factory->isAutomationEnabled() && $this->factory->isStagesEnabled())
			{
				$this->scheme = $this->tunnelManager->getScheme();
			}
			else
			{
				$this->scheme = [
					'available' => true,
					'stages' => []
				];
			}
		}

		return $this->scheme;
	}

	public function executeComponent(): void
	{
		$this->init();
		if ($this->getErrors())
		{
			$this->showError(implode(', ', $this->getErrorMessages()));
			return;
		}

		Bitrix\UI\Toolbar\Facade\Toolbar::deleteFavoriteStar();

		// todo if stages disabled
		$this->arResult['entityTypeId'] = $this->factory->getEntityTypeId();
		$this->arResult['documentType'] = CCrmBizProcHelper::ResolveDocumentType($this->arResult['entityTypeId']);
		$this->arResult['categories'] = $this->getCategories();
		$this->arResult['stages'] = $this->getStages();
		$this->arResult['tunnelScheme'] = $this->getTunnelScheme();
		$this->arResult['analyticsLabels'] = $this->getAnalyticsLabels();

		$this->arResult['isCategoryEditable'] = $this->isCategoryEditable();
		$this->arResult['isCategoryCreatable'] = $this->isCategoryCreatable();
		$this->arResult['isCategoryAccessEditable'] = $this->isCategoryAccessEditable();
		$this->arResult['isAutomationEnabled'] = $this->isAutomationEnabled();
		$this->arResult['isStagesEnabled'] = $this->factory->isStagesEnabled();
		$this->arResult['areStagesEditable'] = $this->areStagesEditable();
		$this->arResult['areTunnelsEditable'] = $this->areTunnelsEditable();
		$this->arResult['isAvailableGenerator'] = $this->isAvailableGenerator();

		$this->arResult['robotsUrl'] = $this->getRobotsUrl();
		$this->arResult['generatorUrl'] = $this->getGeneratorUrl();

		$this->includeComponentTemplate();
	}

	public static function canCurrentUserEditTunnels(): bool
	{
		return Crm\Service\Container::getInstance()->getUserPermissions()->isCrmAdmin();
	}

	private function isCategoryEditable()
	{
		// A lead has no funnels at all, which is a condition of the screen and not of the right.
		return $this->canManageFunnels() && $this->factory->getEntityTypeId() !== \CCrmOwnerType::Lead;
	}

	private function isCategoryCreatable()
	{
		return $this->canManageFunnels() && $this->factory->getEntityTypeId() !== \CCrmOwnerType::Lead;
	}

	/**
	 * The access of a funnel is a right of its own, and a lead has no funnel to give access to - the same
	 * screen condition the funnel buttons carry.
	 */
	private function isCategoryAccessEditable(): bool
	{
		return $this->canManageCategoryAccess() && $this->factory->getEntityTypeId() !== \CCrmOwnerType::Lead;
	}

	private function areStagesEditable()
	{
		return $this->canManageStages();
	}

	/**
	 * The tunnel right of the screen as a whole. Every funnel carries the right of its own funnel
	 * (`ARE_TUNNELS_EDITABLE` of {@see getCategories()}), and this one is what is left for the funnel the
	 * screen offers to create and for a funnel that arrives without a right of its own.
	 */
	private function areTunnelsEditable(): bool
	{
		return $this->canManageTunnels();
	}

	private function canManageFunnels(): bool
	{
		return $this->configuration()->canManageFunnels(
			ConfigurationCallSite::SalesTunnelsComponent,
			$this->getConfigurationScope(),
		);
	}

	private function canManageStages(): bool
	{
		return $this->configuration()->canManageStages(
			ConfigurationCallSite::SalesTunnelsComponent,
			$this->getConfigurationScope(),
		);
	}

	/**
	 * The right in any one funnel of the entity type, which is what a screen showing every funnel of it
	 * opens for. What each funnel of it permits is another question - {@see canManageTunnelsInCategory()}.
	 */
	private function canManageTunnels(): bool
	{
		foreach ($this->getTunnelCategoryIds() as $categoryId)
		{
			if ($this->canManageTunnelsInCategory($categoryId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The tunnels of one funnel: a tunnel belongs to the funnel it starts in, and the write actions of it
	 * are checked in that same contour - {@see Crm\Automation\TunnelManager::canWriteTunnels()}, which
	 * resolves the funnel from the stage on the server. A `null` funnel is the entity type as a whole,
	 * which is all an entity type without funnels has to ask about.
	 */
	private function canManageTunnelsInCategory(?int $categoryId): bool
	{
		return $this->configuration()->canManageTunnels(
			ConfigurationCallSite::SalesTunnelsComponent,
			ConfigurationScope::ofEntityType($this->factory->getEntityTypeId(), $categoryId),
		);
	}

	/**
	 * The funnels the screen shows, as the contours their tunnels belong to. An entity type with no funnel
	 * of its own - a lead - is a single contour of the entity type, and so is one whose funnels are not
	 * made yet.
	 *
	 * @return array<int, int|null>
	 */
	private function getTunnelCategoryIds(): array
	{
		if (!$this->factory->isCategoriesSupported())
		{
			return [null];
		}

		$categoryIds = [];
		foreach ($this->factory->getCategories() as $category)
		{
			$categoryIds[] = $category->getId();
		}

		return $categoryIds === [] ? [null] : $categoryIds;
	}

	private function canManageCategoryAccess(): bool
	{
		return $this->configuration()->canManageCategoryAccess(
			ConfigurationCallSite::SalesTunnelsComponent,
			$this->getConfigurationScope(),
		);
	}

	private function configuration(): Crm\Service\UserPermissions\Configuration
	{
		return $this->userPermissions->configuration();
	}

	/**
	 * The contour of the entity type, without a funnel: this is the form the screen used to ask its
	 * check in, and every capability of it but the tunnels is decided for the entity type as a whole.
	 * The tunnels are asked for in the contour of their own funnel - {@see canManageTunnelsInCategory()}.
	 */
	private function getConfigurationScope(): ConfigurationScope
	{
		return ConfigurationScope::ofEntityType($this->factory->getEntityTypeId());
	}

	private function isAutomationEnabled()
	{
		return $this->factory->isAutomationEnabled();
	}

	private function isAvailableGenerator()
	{
		if ($this->factory->getEntityTypeId() === \CCrmOwnerType::Lead
			|| $this->factory->getEntityTypeId() === \CCrmOwnerType::Deal)
		{
			return Bitrix\Crm\Integration\Sender\Rc\Service::canUse();
		}
		return false;
	}

	private function addAccessDeniedError(): void
	{
		$this->addError(new Error(Loc::getMessage('CRM_SALES_TUNNELS_ACCESS_DENIED2')));
	}

	private function showError($message): void
	{
		echo <<<HTML
			<div class="ui-alert ui-alert-danger ui-alert-icon-danger">
				<span class="ui-alert-message">{$message}</span>
			</div>
HTML;
	}

	protected function getRobotsUrl(): ?string
	{
		if (!$this->factory)
		{
			return null;
		}
		$entityTypeId = $this->factory->getEntityTypeId();
		$template = Crm\Service\Container::getInstance()->getRouter()->getAutomationUrlTemplate($entityTypeId);
		if ($template)
		{
			return str_replace([
				'#entityTypeId#',
				'#categoryId#',
			], [
				$entityTypeId,
				'{category}',
			], $template);
		}

		return null;
	}

	protected function getGeneratorUrl()
	{
		return $this->factory->getEntityTypeId() === \CCrmOwnerType::Lead ? Rc\Service::getPathToAddLead() : Rc\Service::getPathToAddDeal();
	}

	public function configureActions(): array
	{
		return [];
	}

	/**
	 * @param array $data
	 * @return array
	 */
	public function createCategoryAction(array $data = []): ?array
	{
		$this->init();
		if($this->getErrors())
		{
			return null;
		}
		if (!$this->canManageFunnels())
		{
			$this->addAccessDeniedError();

			return null;
		}

		$categoryParams = [];
		if (isset($data['name']) && is_string($data['name']))
		{
			$categoryParams['name'] = $data['name'];
		}
		if (isset($data['sort']) && is_numeric($data['sort']))
		{
			$categoryParams['sort'] = $data['sort'];
		}

		RolePermissionLogContext::getInstance()->set([
			'component' => 'crm.sales.tunnels',
			'scenario' => 'add category',
			'entityTypeId' => $this->factory->getEntityTypeId(),
		]);

		$newCategory = $this->factory->createCategory($categoryParams);
		$result = $newCategory->save();

		if ($result->isSuccess())
		{
			$categories = $this->getCategories();
			foreach ($categories as $key => $category)
			{
				if ($category['ID'] === $newCategory->getId())
				{
					return $category;
				}
			}
		}
		else
		{
			$this->addErrors($result->getErrors());
		}

		return null;
	}

	/**
	 * @param array $data
	 * @return array|null
	 */
	public function getCategoryAction(array $data = []): ?array
	{
		$this->init();
		if($this->getErrors())
		{
			return null;
		}

		$category = $this->factory->getCategory($data['id']);

		return $category ? $category->getData() : null;
	}

	/**
	 * @param array $data
	 * @return void
	 */
	public function updateCategoryAction(array $data = []): void
	{
		$this->init();
		if($this->getErrors())
		{
			return;
		}
		if (!$this->canManageFunnels())
		{
			$this->addAccessDeniedError();

			return;
		}
		$category = $this->factory->getCategory((int)$data['id']);
		if (!$category)
		{
			$this->addError(new Error(Loc::getMessage('CRM_TYPE_CATEGORY_NOT_FOUND_ERROR')));
			return;
		}
		$fields = $data['fields'] ?? [];
		$name = $fields['NAME'] ?? '';
		$sort = isset($fields['SORT']) ? (int)$fields['SORT'] : null;
		if ($name)
		{
			$category->setName($name);
		}
		if ($sort)
		{
			$category->setSort($sort);
		}

		$result = $category->save();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	/**
	 * @param array $data
	 * @return void
	 */
	public function accessCategoryAction(array $data = []): void
	{
		$this->init();
		if ($this->getErrors())
		{
			return;
		}
		if (!$this->canManageCategoryAccess())
		{
			$this->addAccessDeniedError();

			return;
		}

		$entityTypeId = $this->factory->getEntityTypeId();
		$categoryId = (int)$data['id'];

		$isNewPermissionsCorrect = in_array(
			$data['access'],
			[$this->userPermissions::PERMISSION_ALL, $this->userPermissions::PERMISSION_SELF],
			true
		);
		$newPermissions = $isNewPermissionsCorrect
			? $data['access']
			: BX_CRM_PERM_NONE
		;

		RolePermissionLogContext::getInstance()->set([
			'component' => 'crm.sales.tunnels',
			'scenario' => 'set access to sales tunnels',
			'entityTypeId' => $entityTypeId,
			'categoryId' => $categoryId,
			'permissionLevel' => $newPermissions,
		]);

		$result = Crm\Category\CategoryPermissionsManager::getInstance()->setPermissions(new \Bitrix\Crm\CategoryIdentifier($entityTypeId, $categoryId), $newPermissions);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
		RolePermissionLogContext::getInstance()->clear();
	}

	/**
	 * @param array $data
	 * @return void
	 */
	public function copyAccessCategoryAction(array $data = []): void
	{
		$this->init();
		if($this->getErrors())
		{
			return;
		}

		if (!$this->canManageCategoryAccess())
		{
			$this->addAccessDeniedError();

			return;
		}

		RolePermissionLogContext::getInstance()->set([
			'component' => 'crm.sales.tunnels',
			'scenario' => 'copy access between funnels',
			'entityTypeId' => $this->factory->getEntityTypeId(),
			'from' => (int)$data['donorId'],
			'to' => (int)$data['id'],
		]);

		$result = Crm\Category\CategoryPermissionsManager::getInstance()->copyPermissions(
			new \Bitrix\Crm\CategoryIdentifier($this->factory->getEntityTypeId(), (int)$data['donorId']),
			new \Bitrix\Crm\CategoryIdentifier($this->factory->getEntityTypeId(), (int)$data['id']),
		);
		RolePermissionLogContext::getInstance()->clear();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	/**
	 * @param array $data
	 * @return void
	 */
	public function removeCategoryAction(array $data = []): void
	{
		$this->init();
		if($this->getErrors())
		{
			return;
		}
		if (!$this->canManageFunnels())
		{
			$this->addAccessDeniedError();

			return;
		}
		$category = $this->factory->getCategory((int)$data['id']);
		if (!$category)
		{
			$this->addError(new Error(Loc::getMessage('CRM_TYPE_CATEGORY_NOT_FOUND_ERROR')));
			return;
		}
		$result = $category->delete();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	public function createRobotAction(array $data): ?array
	{
		$this->init();
		if($this->getErrors())
		{
			return null;
		}
		if (!$this->factory->isAutomationEnabled())
		{
			$this->addError(new Error(Loc::getMessage('CRM_SALES_TUNNELS_ROBOTS_NOT_SUPPORTED')));
			return null;
		}

		$userId = $this->userPermissions->getUserId();
		$result = $this->tunnelManager->addTunnel(
			$userId,
			$data['from']['category'],
			$data['from']['stage'],
			$data['to']['category'],
			$data['to']['stage'],
			$data['robotAction']
		);

		if ($result->isSuccess())
		{
			return $result->getData();
		}

		$this->addErrors($result->getErrors());
		return null;
	}

	public function removeRobotAction(array $data): void
	{
		$this->init();
		if($this->getErrors())
		{
			return;
		}
		if (!$this->factory->isAutomationEnabled())
		{
			$this->addError(new Error(Loc::getMessage('CRM_SALES_TUNNELS_ROBOTS_NOT_SUPPORTED')));
			return;
		}

		$userId = $this->userPermissions->getUserId();
		$result = $this->tunnelManager->removeTunnel($userId, $data);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	public function updateRobotAction(array $data): ?array
	{
		$this->init();
		if($this->getErrors())
		{
			return null;
		}
		if (!$this->factory->isAutomationEnabled())
		{
			$this->addError(new Error(Loc::getMessage('CRM_SALES_TUNNELS_ROBOTS_NOT_SUPPORTED')));
			return null;
		}
		$userId = $this->userPermissions->getUserId();

		$result = $this->tunnelManager->updateTunnel($userId, $data);
		if ($result->isSuccess())
		{
			return $result->getData();
		}

		$this->addErrors($result->getErrors());
		return null;
	}

	public function addStageAction(array $data): ?array
	{
		$this->init();
		if($this->getErrors())
		{
			return null;
		}
		if (!$this->validateStageScope($data))
		{
			return null;
		}
		$status = new CCrmStatus($data['entityId']);
		$id = isset($data['afterStageId'])
			? $this->addStageAfter($status, $data, (int)$data['afterStageId'])
			: $status->Add($this->buildNewStageFields($data, (int)$data['sort']))
		;

		if (!$id)
		{
			if ($this->getErrors())
			{
				return null;
			}

			$errorMessage = trim((string)$status->GetLastError());
			$this->addError(new Error(
				$errorMessage !== ''
					? $errorMessage
					: Loc::getMessage('CRM_SALES_STAGE_CREATE_ERROR')
			));
			return null;
		}

		$this->factory->purgeStagesCache();
		$stage = $this->getStageById($id);
		if ($stage === null)
		{
			return null;
		}

		$this->sendStagePullEvent('STAGE_ADDED', $stage);

		return [
			'stage' => $stage,
		];
	}

	private function addStageAfter(CCrmStatus $status, array $data, int $afterStageId): ?int
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$stages = Crm\StatusTable::getList([
				'select' => ['ID', 'SORT'],
				'filter' => ['=ENTITY_ID' => $data['entityId']],
				'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
			])->fetchAll();
			$afterStageIndex = array_search($afterStageId, array_map('intval', array_column($stages, 'ID')), true);
			if ($afterStageIndex === false)
			{
				$this->rollbackStageTransaction($connection);
				$this->addError(new Error(Loc::getMessage('CRM_SALES_TUNNELS_STAGE_NOT_FOUND')));

				return null;
			}

			$id = $status->Add(
				$this->buildNewStageFields($data, (int)$stages[$afterStageIndex]['SORT'] + 1),
			);
			if (!$id)
			{
				$this->rollbackStageTransaction($connection);

				return null;
			}

			array_splice($stages, $afterStageIndex + 1, 0, [[
				'ID' => $id,
				'SORT' => 0,
			]]);
			$sort = 10;
			foreach ($stages as $stage)
			{
				if ((int)$stage['SORT'] !== $sort)
				{
					$updatedId = $status->Update((int)$stage['ID'], ['SORT' => $sort]);
					$errorMessage = trim((string)$status->GetLastError());
					if (!$updatedId || $errorMessage !== '')
					{
						$this->rollbackStageTransaction($connection);
						$this->addError(new Error(
							$errorMessage !== ''
								? $errorMessage
								: Loc::getMessage('CRM_SALES_STAGE_UPDATE_ERROR'),
						));

						return null;
					}
				}

				$sort += 10;
			}

			$connection->commitTransaction();

			return (int)$id;
		}
		catch (\Throwable $exception)
		{
			$this->rollbackStageTransaction($connection);

			throw $exception;
		}
	}

	private function buildNewStageFields(array $data, int $sort): array
	{
		return [
			'NAME' => $data['name'],
			'SORT' => $sort,
			'COLOR' => $data['color'],
			'DESCRIPTION' => $data['description'] ?? '',
			'SEMANTICS' => (isset($data['semantics'])
				&& in_array($data['semantics'], [PhaseSemantics::SUCCESS, PhaseSemantics::FAILURE], true))
				? $data['semantics']
				: null,
			'CATEGORY_ID' => $data['categoryId'],
		];
	}

	private function rollbackStageTransaction(?\Bitrix\Main\DB\Connection &$connection): void
	{
		if ($connection === null)
		{
			return;
		}

		$transactionConnection = $connection;
		$connection = null;
		try
		{
			$transactionConnection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	public function updateStageAction(array $data): array
	{
		$this->init();
		if($this->getErrors())
		{
			return [
				'success' => false,
				'errors' => $this->getErrorMessages(),
			];
		}
		if (!$this->validateStageScope($data))
		{
			return [
				'success' => false,
				'errors' => $this->getErrorMessages(),
			];
		}
		$response = [
			'success' => false,
			'errors' => [],
		];
		$status = new CCrmStatus($data['entityId']);
		$stage = $status->GetStatusById($data['stageId']);

		if ($stage)
		{
			$fields = [];

			if (isset($data['name']) && is_string($data['name']))
			{
				$fields['NAME'] = $data['name'];
			}

			if (isset($data['sort']) && (int)$data['sort'] > 0)
			{
				$fields['SORT'] = (int)$data['sort'];
			}
			else
			{
				$fields['SORT'] = (int)$stage['SORT'];
			}

			$fields['COLOR'] = $data['color'] ?? '';
			if (array_key_exists('description', $data))
			{
				$fields['DESCRIPTION'] = $data['description'];
			}

			$id = $status->Update($data['stageId'], $fields);
			$errorMessage = trim((string)$status->GetLastError());

			if (!$id || $errorMessage !== '')
			{
				$response['errors'][] = $errorMessage !== ''
					? $errorMessage
					: Loc::getMessage('CRM_SALES_STAGE_UPDATE_ERROR')
				;
				return $response;
			}

			$response['success'] = true;
			$response['stage'] = $this->getStageById($id);

			$this->sendStagePullEvent('STAGE_UPDATED', $response['stage']);
		}
		else
		{
			$response['errors'][] = Loc::getMessage('CRM_SALES_TUNNELS_STAGE_NOT_FOUND');
		}

		return $response;
	}

	private function validateStageScope(array &$data): bool
	{
		$entityTypeId = $this->factory->getEntityTypeId();
		$categoryId = (int)($data['categoryId'] ?? 0);
		if (
			!$this->factory->isCategoriesSupported()
			&& $entityTypeId === \CCrmOwnerType::Lead
			&& $categoryId === \CCrmOwnerType::Lead
		)
		{
			$categoryId = 0;
			$data['categoryId'] = $categoryId;
		}

		$isCategoryValid = $this->factory->isCategoriesSupported()
			? $this->factory->getCategory($categoryId) !== null
			: $categoryId === 0;
		try
		{
			$expectedEntityId = $isCategoryValid
				? $this->factory->getStagesEntityId($categoryId)
				: null
			;
		}
		catch (\Throwable)
		{
			$expectedEntityId = null;
		}

		if (
			!$isCategoryValid
			|| !is_string($expectedEntityId)
			|| !isset($data['entityId'])
			|| !is_string($data['entityId'])
			|| !hash_equals($expectedEntityId, $data['entityId'])
			|| !$this->canManageStages()
		)
		{
			$this->addAccessDeniedError();

			return false;
		}

		return true;
	}

	public function removeStageAction(array $data): void
	{
		$this->init();
		if($this->getErrors())
		{
			return;
		}
		if (!$this->canManageStages())
		{
			$this->addAccessDeniedError();

			return;
		}
		$stage = $this->factory->getStage($data['statusId']);
		if ($stage)
		{
			if ($stage->getSystem())
			{
				$this->addError(new Error(Loc::getMessage('CRM_SALES_TUNNELS_STAGE_IS_SYSTEM')));
				return;
			}
			$result = $stage->delete();
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());
				return;
			}

			$this->sendStagePullEvent('STAGE_DELETED', $stage->collectValues());

			return;
		}
		$this->addError(new Error(Loc::getMessage('CRM_SALES_TUNNELS_STAGE_NOT_FOUND')));
	}

	public function updateStagesAction(array $data): array
	{
		return array_map(
			function($itemData)
			{
				return $this->updateStageAction($itemData);
			},
			$data
		);
	}

	public function getCategoriesAction(): ?array
	{
		$this->init();
		if($this->getErrors())
		{
			return null;
		}
		return $this->getCategories();
	}

	protected function sendStagePullEvent(string $eventName, array $stage): void
	{
		$allowedEvents = [
			'STAGE_ADDED',
			'STAGE_UPDATED',
			'STAGE_DELETED',
		];

		if (!in_array($eventName, $allowedEvents, true))
		{
			return;
		}

		$entityTypeName = $this->factory->getEntityName();
		$item =
			Crm\Kanban\Entity::getInstance($entityTypeName)
				?->createPullStage($stage)
		;

		switch ($eventName)
		{
			case 'STAGE_ADDED':
				PullManager::getInstance()->sendStageAddedEvent(
					$item,
					[
						'TYPE' => $entityTypeName,
						'CATEGORY_ID' => (int)($stage['CATEGORY_ID'] ?? 0),
					],
				);

				break;
			case 'STAGE_UPDATED':
				PullManager::getInstance()->sendStageUpdatedEvent(
					$item,
					[
						'TYPE' => $entityTypeName,
						'CATEGORY_ID' => (int)($stage['CATEGORY_ID'] ?? 0),
					],
				);

				break;
			case 'STAGE_DELETED':
				PullManager::getInstance()->sendStageDeletedEvent(
					$item,
					[
						'TYPE' => $entityTypeName,
						'CATEGORY_ID' => (int)($stage['CATEGORY_ID'] ?? 0),
					],
				);

				break;
		}
	}

	protected function getToolbarParameters(): array
	{
		$buttonsRight = [
			Buttons\IntranetBindingMenu::createByComponentParameters([
				'SECTION_CODE' => Integration\Intranet\BindingMenu\SectionCode::TUNNELS,
				'MENU_CODE' => Integration\Intranet\BindingMenu\CodeBuilder::getMenuCode(
					$this->factory->getEntityTypeId(),
				),
			])
		];

		if ($this->isCategoryCreatable())
		{
			$buttonsRight[] = new Buttons\Button([
				'color' => Buttons\Color::LIGHT_BORDER,
				'icon' => Buttons\Icon::INFO,
				// used in js
				'className' => 'crm-st-help-button',
				'text' => Loc::getMessage('CRM_ST_HELP_BUTTON'),
			]);

			$buttonsRight[] = new Buttons\Button([
				'color' => Buttons\Color::PRIMARY,
				// used in js
				'className' => 'crm-st-add-category-btn-top',
				'text' => Loc::getMessage('CRM_ST_ADD_FUNNEL_BUTTON2'),
			]);
		}

		return array_merge(
			parent::getToolbarParameters(),
			[
				'buttons' => [
					\Bitrix\UI\Toolbar\ButtonLocation::RIGHT => $buttonsRight,
				],
			],
		);
	}

	// Return array signature is synchronized with template .default/src/type/analytics-labels.js
	private function getAnalyticsLabels(): array
	{
		$funnelSubSection = Dictionary::SUB_SECTION_FUNNEL;
		$kanbanSubSection = Dictionary::SUB_SECTION_KANBAN;

		$createFunnel = (new FunnelAnalytics\Funnel\CreateEvent(subSection: $funnelSubSection))->buildData();
		$renameFunnel = (new FunnelAnalytics\Funnel\RenameEvent(subSection: $funnelSubSection))->buildData();
		$deleteFunnel = (new FunnelAnalytics\Funnel\DeleteEvent(subSection: $funnelSubSection))->buildData();

		$createStage = (new FunnelAnalytics\Stage\CreateEvent(subSection: $funnelSubSection))->buildData();
		$renameStage = (new FunnelAnalytics\Stage\RenameEvent(subSection: $funnelSubSection))->buildData();
		$deleteStage = (new FunnelAnalytics\Stage\DeleteEvent(subSection: $funnelSubSection))->buildData();
		$updateStage = (new FunnelAnalytics\Stage\UpdateEvent(subSection: $funnelSubSection))->buildData();

		$createTunnel = (new FunnelAnalytics\Tunnel\CreateEvent(subSection: $funnelSubSection))->buildData();
		$deleteTunnel = (new FunnelAnalytics\Tunnel\DeleteEvent(subSection: $funnelSubSection))->buildData();

		$openSlider = (new FunnelAnalytics\Funnel\OpenSliderEvent(subSection: $kanbanSubSection))->buildData();

		return [
			'createFunnelLabel' => $createFunnel,
			'renameFunnelLabel' => $renameFunnel,
			'deleteFunnelLabel' => $deleteFunnel,

			'createStageLabel' => $createStage,
			'renameStageLabel' => $renameStage,
			'deleteStageLabel' => $deleteStage,
			'updateStageLabel' => $updateStage,

			'createTunnelLabel' => $createTunnel,
			'deleteTunnelLabel' => $deleteTunnel,

			'openSliderLabel' => $openSlider,
		];
	}

	private function isSalesTunnelsEnabled(Crm\Service\Factory $factory): bool
	{
		$availabilityMap = [
			CCrmOwnerType::Lead => true,
			CCrmOwnerType::Contact => false,
			CCrmOwnerType::Company => false,
		];

		return
			$availabilityMap[$factory->getEntityTypeId()]
			?? $factory->isCategoriesEnabled()
		;
	}
}
