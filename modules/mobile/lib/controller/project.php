<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Internal\Dto\Project\ProjectCreateDto;
use Bitrix\Mobile\Internal\Dto\Project\ProjectSaveResultDto;
use Bitrix\Mobile\Internal\Services\Project\ProjectAdapterService;
use Bitrix\Mobile\Internal\Services\Project\ProjectAdapterServiceFactory;
use Bitrix\Mobile\Internal\Services\Project\ProjectReadService;
use Bitrix\Mobile\Internal\Services\Project\ProjectReadServiceFactory;
use Bitrix\Mobile\Trait\PublicErrorsTrait;
use Bitrix\Socialnetwork\Helper;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectAccessProvider;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectViewInfoProvider;
use RuntimeException;

Loc::loadMessages(__FILE__);

final class Project extends JsonController
{
	use PublicErrorsTrait;

	private ?ProjectReadService $projectReadService = null;
	private ?ProjectAdapterService $projectAdapterService = null;
	private ?ProjectAccessProvider $projectAccessProvider = null;
	private ?ProjectViewInfoProvider $projectViewInfoProvider = null;

	/**
	 * @restMethod mobile.Project.getCreateSettings
	 */
	#[CloseSession]
	public function getCreateSettingsAction(): array
	{
		return [
			'ownerData' => $this->getProjectReadService()->getCurrentUserData(),
			'autoDeleteEnabledInPortalSettings' => Option::get('im', 'isAutoDeleteMessagesEnabled', 'Y') === 'Y',
			'notifications' => $this->getProjectReadService()->getDefaultNotificationCatalog(),
		];
	}

	/**
	 * @restMethod mobile.Project.getEditSettings
	 */
	#[CloseSession]
	public function getEditSettingsAction(int $projectId): ?array
	{
		if (!$this->canModifyProject($projectId))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED')));

			return null;
		}

		return array_merge(
			$this->getCreateSettingsAction(),
			$this->getProjectReadService()->getEditSettings($projectId),
		);
	}

	/**
	 * @restMethod mobile.Project.create
	 */
	#[CloseSession]
	public function createAction(array $fields): ?ProjectSaveResultDto
	{
		if (!$this->canCreateProject($fields))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED')));

			return null;
		}

		try
		{
			$result = $this->getProjectAdapterService()->create(ProjectCreateDto::fromArray($fields));
		}
		catch (RuntimeException $exception)
		{
			$this->addErrors($this->markErrorsAsPublic([
				new Error($exception->getMessage(), 'PROJECT_CREATE_ERROR'),
			]));

			return null;
		}

		return $result->withIsTrialTurnedOn($this->turnOnProjectsTrialIfNeeded());
	}

	/**
	 * @restMethod mobile.Project.update
	 */
	#[CloseSession]
	public function updateAction(int $projectId, array $fields): ?ProjectSaveResultDto
	{
		if (!$this->canModifyProject($projectId))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED')));

			return null;
		}

		try
		{
			return $this->getProjectAdapterService()->update($projectId, ProjectCreateDto::fromArray($fields));
		}
		catch (RuntimeException $exception)
		{
			$this->addErrors($this->markErrorsAsPublic([
				new Error($exception->getMessage(), 'PROJECT_UPDATE_ERROR'),
			]));

			return null;
		}
	}

	/**
	 * @restMethod mobile.Project.updateChatSettings
	 */
	#[CloseSession]
	public function updateChatSettingsAction(
		int $projectId,
		?string $messageWriters = null,
		?string $showHistory = null,
		?int $messagesAutoDeleteDelay = null,
	): ?bool
	{
		if (!$this->canModifyProject($projectId))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED')));

			return null;
		}

		$this->getProjectAdapterService()->updateChatSettings(
			$projectId,
			$messageWriters,
			$showHistory,
			$messagesAutoDeleteDelay,
		);

		return true;
	}

	/**
	 * @restMethod mobile.Project.getChatId
	 */
	#[CloseSession]
	public function getChatIdAction(int $projectId): ?array
	{
		if (!$this->canViewProject($projectId))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED')));

			return null;
		}

		if (
			Loader::includeModule('im')
			&& Option::get('socialnetwork', 'new_projects', 'N') === 'Y'
		)
		{
			return [
				'chatId' => (int)\CIMChat::GetSonetGroupChatId(
					$projectId,
					(int)$this->getCurrentUser()?->getId(),
				),
			];
		}

		return ['chatId' => 0];
	}

	/**
	 * @restMethod mobile.Project.getHasCollabers
	 */
	#[CloseSession]
	public function getHasCollabersAction(int $projectId): ?array
	{
		if (!$this->canViewProject($projectId))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED')));

			return null;
		}

		if (!class_exists(ProjectProvider::class))
		{
			return ['hasCollabers' => false];
		}

		return [
			'hasCollabers' => ServiceLocator::getInstance()->get(ProjectProvider::class)->hasCollabers($projectId),
		];
	}

	/**
	 * @restMethod mobile.Project.getViewInfo
	 */
	#[CloseSession]
	public function getViewInfoAction(int $projectId): ?array
	{
		$viewInfo = $this->getProjectViewInfoProvider()->getViewInfoWithAccessCheck(
			projectId: $projectId,
			userId: (int)$this->getCurrentUser()?->getId(),
			checkAdminSession: ($this->getScope() !== self::SCOPE_REST),
		);

		if ($viewInfo === null)
		{
			$this->addError(new Error(
				Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED'),
				'PROJECT_ACCESS_DENIED',
			));

			return null;
		}

		return $viewInfo->toArray();
	}

	/**
	 * @restMethod mobile.Project.updateTaskPermissions
	 */
	#[CloseSession]
	public function updateTaskPermissionsAction(
		int $projectId,
		string $viewAll,
		string $sort,
		string $createTasks,
		string $editTasks,
		string $deleteTasks,
	): ?bool
	{
		if (!$this->canModifyProject($projectId))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED')));

			return null;
		}

		$this->getProjectAdapterService()->updateTaskPermissions(
			$projectId,
			$viewAll,
			$sort,
			$createTasks,
			$editTasks,
			$deleteTasks,
		);

		return true;
	}

	/**
	 * @restMethod mobile.Project.updateKnowledgePermissions
	 */
	#[CloseSession]
	public function updateKnowledgePermissionsAction(
		int $projectId,
		string $read,
		string $edit,
		string $settings,
		string $delete,
	): ?bool
	{
		if (!$this->canModifyProject($projectId))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_CONTROLLER_PROJECT_ACCESS_DENIED')));

			return null;
		}

		$this->getProjectAdapterService()->updateKnowledgePermissions(
			$projectId,
			$read,
			$edit,
			$settings,
			$delete,
		);

		return true;
	}

	protected function init(): void
	{
		parent::init();

		Loader::requireModule('socialnetwork');
		Loader::requireModule('im');
	}

	private function canModifyProject(int $projectId): bool
	{
		return Helper\Workgroup\Access::canModify([
			'groupId' => $projectId,
			'checkAdminSession' => ($this->getScope() !== self::SCOPE_REST),
		]);
	}

	private function canCreateProject(array $fields): bool
	{
		$siteId = is_string($fields['siteId'] ?? null) && $fields['siteId'] !== ''
			? $fields['siteId']
			: SITE_ID;

		return Helper\Workgroup\Access::canCreate([
			'userId' => (int)$this->getCurrentUser()?->getId(),
			'siteId' => $siteId,
			'checkAdminSession' => ($this->getScope() !== self::SCOPE_REST),
		]);
	}

	private function canViewProject(int $projectId): bool
	{
		return $this->getProjectAccessProvider()->canView(
			projectId: $projectId,
			userId: (int)$this->getCurrentUser()?->getId(),
			checkAdminSession: ($this->getScope() !== self::SCOPE_REST),
		);
	}

	private function turnOnProjectsTrialIfNeeded(): bool
	{
		$feature = Helper\Feature::PROJECTS_GROUPS;

		if (!Helper\Feature::isFeatureEnabled($feature) && Helper\Feature::canTurnOnTrial($feature))
		{
			Helper\Feature::turnOnTrial($feature);

			return true;
		}

		return false;
	}

	private function getProjectReadServiceFactory(): ProjectReadServiceFactory
	{
		return ServiceLocator::getInstance()->get(ProjectReadServiceFactory::class);
	}

	private function getProjectAdapterServiceFactory(): ProjectAdapterServiceFactory
	{
		return ServiceLocator::getInstance()->get(ProjectAdapterServiceFactory::class);
	}

	private function getProjectReadService(): ProjectReadService
	{
		return $this->projectReadService ??= $this->getProjectReadServiceFactory()->createReadService(
			(int)$this->getCurrentUser()?->getId(),
		);
	}

	private function getProjectAdapterService(): ProjectAdapterService
	{
		return $this->projectAdapterService ??= $this->getProjectAdapterServiceFactory()->createAdapterService(
			(int)$this->getCurrentUser()?->getId(),
		);
	}

	private function getProjectAccessProvider(): ProjectAccessProvider
	{
		return $this->projectAccessProvider ??= ServiceLocator::getInstance()->get(ProjectAccessProvider::class);
	}

	private function getProjectViewInfoProvider(): ProjectViewInfoProvider
	{
		return $this->projectViewInfoProvider ??= ServiceLocator::getInstance()->get(ProjectViewInfoProvider::class);
	}
}
