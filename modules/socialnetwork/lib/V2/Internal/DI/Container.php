<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\DI;

use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreate\ProjectAttachBuilder;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\ConvertProjectAccessService;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\LegacyGroupAccessService;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\ProjectAccessService;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\ScrumAccessService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Bitrix24\Service\Portal;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Service\CalendarListService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Service\CalendarSettingsService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider\ProjectChatDataProvider;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Sync\ProjectChatMemberSyncService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ProjectChatHider;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ProjectChatResolver;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Service\StructureRelationService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ConvertChatService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\TaskListService;
use Bitrix\Socialnetwork\V2\Internal\Repository\CollabOptionRepository;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\ConvertService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\ProjectCounterFilterService;
use Bitrix\Socialnetwork\V2\Internal\Repository\FavoritesRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectFeatureRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupCounterRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupFilterRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\Counter\ProjectCounterService;
use Bitrix\Socialnetwork\V2\Internal\Service\Counter\ScrumCounterService;
use Bitrix\Socialnetwork\V2\Internal\Service\FavoritesService;
use Bitrix\Socialnetwork\V2\Internal\Service\Grid\ProjectListGridRowService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureAvailabilityService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\HasCollabersService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureStatesOnConvertService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\LegacyProjectFeaturePolicy;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectCreateFeatures;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectFeatureToggleService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\RealtimePublisher;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectOptionService;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectService;
use Bitrix\Socialnetwork\V2\Internal\Service\ScrumService;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\StructureSyncService;
use Bitrix\Socialnetwork\V2\Internal\Service\UserServiceInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\WorkgroupGridService;
use Bitrix\Socialnetwork\V2\Internal\Service\WorkgroupPinService;
use Bitrix\Socialnetwork\V2\Internal\Service\WorkgroupTagService;
use Bitrix\Socialnetwork\V2\Public\Command\Project\AddProjectHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\ArchiveProjectHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\CopyProjectHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteIncomingRequestHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteOutgoingRequestHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteProjectHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\JoinProjectHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SetSummaryAgentOptionHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SyncProjectChatMembersHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SwitchFavoriteHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SwitchPinHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\UpdateProjectHandler;
use Bitrix\Socialnetwork\V2\Public\Command\Project\UpdateProjectTagsHandler;
use Bitrix\Socialnetwork\V2\Public\Mapper\PinModeMapper;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectMapper;

class Container extends AbstractContainer
{
	public function getProjectRepository(): ProjectRepositoryInterface
	{
		return $this->get(ProjectRepositoryInterface::class);
	}

	public function getProjectService(): ProjectService
	{
		return $this->get(ProjectService::class);
	}

	public function getProjectOptionService(): ProjectOptionService
	{
		return $this->get(ProjectOptionService::class);
	}

	public function getAccessControllerFactory(): ControllerFactoryInterface
	{
		return $this->get(ControllerFactoryInterface::class);
	}

	public function getCollabRegistry(): CollabRegistry
	{
		return $this->get(CollabRegistry::class);
	}

	public function getProjectAccessService(): ProjectAccessService
	{
		return $this->get(ProjectAccessService::class);
	}

	public function getLegacyGroupAccessService(): LegacyGroupAccessService
	{
		return $this->get(LegacyGroupAccessService::class);
	}

	public function getProjectMapper(): ProjectMapper
	{
		return $this->get(ProjectMapper::class);
	}

	public function getPinModeMapper(): PinModeMapper
	{
		return $this->get(PinModeMapper::class);
	}

	public function getAddProjectHandler(): AddProjectHandler
	{
		return $this->get(AddProjectHandler::class);
	}

	public function getUpdateProjectHandler(): UpdateProjectHandler
	{
		return $this->get(UpdateProjectHandler::class);
	}

	public function getDeleteProjectHandler(): DeleteProjectHandler
	{
		return $this->get(DeleteProjectHandler::class);
	}

	public function getArchiveProjectHandler(): ArchiveProjectHandler
	{
		return $this->get(ArchiveProjectHandler::class);
	}

	public function getCopyProjectHandler(): CopyProjectHandler
	{
		return $this->get(CopyProjectHandler::class);
	}

	public function getDeleteIncomingRequestHandler(): DeleteIncomingRequestHandler
	{
		return $this->get(DeleteIncomingRequestHandler::class);
	}

	public function getDeleteOutgoingRequestHandler(): DeleteOutgoingRequestHandler
	{
		return $this->get(DeleteOutgoingRequestHandler::class);
	}

	public function getJoinProjectHandler(): JoinProjectHandler
	{
		return $this->get(JoinProjectHandler::class);
	}

	public function getSetSummaryAgentOptionHandler(): SetSummaryAgentOptionHandler
	{
		return $this->get(SetSummaryAgentOptionHandler::class);
	}

	public function getSyncProjectChatMembersHandler(): SyncProjectChatMembersHandler
	{
		return $this->get(SyncProjectChatMembersHandler::class);
	}

	public function getSwitchFavoriteHandler(): SwitchFavoriteHandler
	{
		return $this->get(SwitchFavoriteHandler::class);
	}

	public function getSwitchPinHandler(): SwitchPinHandler
	{
		return $this->get(SwitchPinHandler::class);
	}

	public function getUpdateProjectTagsHandler(): UpdateProjectTagsHandler
	{
		return $this->get(UpdateProjectTagsHandler::class);
	}

	public function getProjectFeatureService(): FeatureService
	{
		return $this->get(FeatureService::class);
	}

	public function getProjectFeatureAvailabilityService(): FeatureAvailabilityService
	{
		return $this->get(FeatureAvailabilityService::class);
	}

	public function getLegacyProjectFeaturePolicy(): LegacyProjectFeaturePolicy
	{
		return $this->get(LegacyProjectFeaturePolicy::class);
	}

	public function getProjectCreateFeatures(): ProjectCreateFeatures
	{
		return $this->get(ProjectCreateFeatures::class);
	}

	public function getProjectFeatureRepository(): ProjectFeatureRepository
	{
		return $this->get(ProjectFeatureRepository::class);
	}

	public function getProjectFeatureToggleService(): ProjectFeatureToggleService
	{
		return $this->get(ProjectFeatureToggleService::class);
	}

	public function getFeatureStatesOnConvertService(): FeatureStatesOnConvertService
	{
		return $this->get(FeatureStatesOnConvertService::class);
	}

	public function getCalendarSettingsService(): CalendarSettingsService
	{
		return $this->get(CalendarSettingsService::class);
	}

	public function getStructureRelationService(): StructureRelationService
	{
		return $this->get(StructureRelationService::class);
	}

	public function getStructureSyncService(): StructureSyncService
	{
		return $this->get(StructureSyncService::class);
	}

	public function getPortalService(): Portal
	{
		return $this->get(Portal::class);
	}

	public function getExtranetUserService(): ExtranetUserService
	{
		return $this->get(ExtranetUserService::class);
	}

	public function getConvertChatService(): ConvertChatService
	{
		return $this->get(ConvertChatService::class);
	}

	public function getConvertService(): ConvertService
	{
		return $this->get(ConvertService::class);
	}

	public function getProjectChatResolver(): ProjectChatResolver
	{
		return $this->get(ProjectChatResolver::class);
	}

	public function getProjectChatDataProvider(): ProjectChatDataProvider
	{
		return $this->get(ProjectChatDataProvider::class);
	}

	public function getProjectChatHider(): ProjectChatHider
	{
		return $this->get(ProjectChatHider::class);
	}

	public function getProjectChatMemberSyncService(): ProjectChatMemberSyncService
	{
		return $this->get(ProjectChatMemberSyncService::class);
	}

	public function getScrumAccessService(): ScrumAccessService
	{
		return $this->get(ScrumAccessService::class);
	}

	public function getScrumService(): ScrumService
	{
		return $this->get(ScrumService::class);
	}

	public function getUserService(): UserServiceInterface
	{
		return $this->get(UserServiceInterface::class);
	}

	public function getProjectMemberRepository(): ProjectMemberRepositoryInterface
	{
		return $this->get(ProjectMemberRepositoryInterface::class);
	}

	public function getFavoritesRepository(): FavoritesRepositoryInterface
	{
		return $this->get(FavoritesRepositoryInterface::class);
	}

	public function getWorkgroupPinService(): WorkgroupPinService
	{
		return $this->get(WorkgroupPinService::class);
	}

	public function getWorkgroupFilterRepository(): WorkgroupFilterRepositoryInterface
	{
		return $this->get(WorkgroupFilterRepositoryInterface::class);
	}

	public function getWorkgroupCounterRepository(): WorkgroupCounterRepositoryInterface
	{
		return $this->get(WorkgroupCounterRepositoryInterface::class);
	}

	public function getProjectCounterFilterService(): ProjectCounterFilterService
	{
		return $this->get(ProjectCounterFilterService::class);
	}

	public function getProjectCounterService(): ProjectCounterService
	{
		return $this->get(ProjectCounterService::class);
	}

	public function getScrumCounterService(): ScrumCounterService
	{
		return $this->get(ScrumCounterService::class);
	}

	public function getProjectRealtimePublisher(): RealtimePublisher
	{
		return $this->get(RealtimePublisher::class);
	}

	public function getFavoritesService(): FavoritesService
	{
		return $this->get(FavoritesService::class);
	}

	public function getProjectListGridRowService(): ProjectListGridRowService
	{
		return $this->get(ProjectListGridRowService::class);
	}

	public function getWorkgroupGridService(): WorkgroupGridService
	{
		return $this->get(WorkgroupGridService::class);
	}

	public function getWorkgroupTagService(): WorkgroupTagService
	{
		return $this->get(WorkgroupTagService::class);
	}

	public function getTaskListService(): TaskListService
	{
		return $this->get(TaskListService::class);
	}

	public function getConvertProjectAccessService(): ConvertProjectAccessService
	{
		return $this->get(ConvertProjectAccessService::class);
	}

	public function getCalendarListService(): CalendarListService
	{
		return $this->get(CalendarListService::class);
	}

	public function getCollabOptionRepository(): CollabOptionRepository
	{
		return $this->get(CollabOptionRepository::class);
	}

	public function getHasCollabersService(): HasCollabersService
	{
		return $this->get(HasCollabersService::class);
	}

	public function getProjectAttachBuilder(): ProjectAttachBuilder
	{
		return $this->get(ProjectAttachBuilder::class);
	}
}
