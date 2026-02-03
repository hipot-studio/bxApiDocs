<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Extension;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\ModuleManager;
use Bitrix\Tasks\Integration\AI;
use Bitrix\Tasks\Integration\Extranet\User;
use Bitrix\Tasks\Internals\Routes\RouteDictionary;
use Bitrix\Tasks\Util\UserField\Task;
use Bitrix\Tasks\Util\UserField\Task\Template;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Access\Service\FlowRightService;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\Flow\FlowFeature;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository\StructureRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\UserUrlService;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepository;
use Bitrix\Tasks\V2\Internal\Repository\UserFieldSchemeRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\AnalyticsService;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Internal\Service\TariffService;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\StateService;

class ConfigService
{
	public function __construct(
		private readonly TariffService $tariffService,
		private readonly LinkService $linkService,
		private readonly TaskRightService $taskRightService,
		private readonly TemplateRightService $templateRightService,
		private readonly FlowRightService $flowRightService,
		private readonly ToolService $toolService,
		private readonly AnalyticsService $analyticsService,
		private readonly StateService $stateService,
		private readonly UserRepositoryInterface $userRepository,
		private readonly GroupRepository $groupRepository,
		private readonly UserFieldSchemeRepositoryInterface $userFieldSchemeRepository,
		private readonly UserUrlService $userUrlService,
		private readonly StructureRepositoryInterface $structureRepository,
	)
	{

	}

	public function getCoreSettings(int $userId): array
	{
		$isCollaber = User::isCollaber($userId);

		$state = $this->stateService->get($userId);

		$mainDepartmentAccessCode = $this->structureRepository->getMainDepartment()?->accessCode ?? '';
		$mainDepartmentUfId = (new AccessCode($mainDepartmentAccessCode))->getEntityId();

		return [
			'externalExtensions' => ['tasks.external'],
			'currentUser' => $this->userRepository->getByIds([$userId])->findOneById($userId),
			'mainDepartmentUfId' => $mainDepartmentUfId,
			'userOptions' => [
				'fullCard' => \CUserOptions::getOption('tasks', 'fullCard'),
			],
			'rights' => [
				'user' => [
					'admin' => \Bitrix\Tasks\Util\User::isSuper($userId),
				],
				'flow' => $this->flowRightService->getUserRights($userId),
				'tasks' => $this->taskRightService->getUserRights($userId),
				'templates' => $this->templateRightService->getUserRights($userId),
			],
			'defaultCollab' => $this->groupRepository->getDefaultCollab($userId),
			'deadlineUserOption' => $state?->defaultDeadline?->toArray(),
			'stateFlags' => $state?->getFlags(),
			'chatType' => Chat::ENTITY_TYPE,
			'features' => [
				'isV2Enabled' => FormV2Feature::isOn(),
				'isMiniformEnabled' => FormV2Feature::isOn('miniform'),
				'isFlowEnabled' => !$isCollaber && FlowFeature::isOn(),
				'isProjectsEnabled' => $this->toolService->isProjectsAvailable(),
				'isTemplateEnabled' => $this->toolService->isTemplatesAvailable(),
				'isCopilotEnabled' => AI\Settings::isTextAvailable(),
				'crm' => !$isCollaber && ModuleManager::isModuleInstalled('crm'),
				'im' => ModuleManager::isModuleInstalled('im'),
				'disk' => ModuleManager::isModuleInstalled('disk'),
				'allowedGroups' => FormV2Feature::getAllowedGroups(), // @todo Remove 'team_form' before release
			],
			'paths' => [
				'editPath' => $this->linkService->getCreateTask($userId),
				'userTaskPathTemplate' => RouteDictionary::PATH_TO_USER_TASK,
				'groupTaskPathTemplate' => RouteDictionary::PATH_TO_GROUP_TASK,
			],
			'ahaMoments' => Container::getInstance()->getAhaMomentProvider()->get($userId),
			'restrictions' => Container::getInstance()->getTariffProvider()->getRestrictions(),
			'taskUserFieldScheme' => $this->userFieldSchemeRepository->getCollection($userId, Task::getEntityCode())->toArray(),
			'templateUserFieldScheme' => $this->userFieldSchemeRepository->getCollection($userId, Template::getEntityCode())->toArray(),
		];
	}

	public function getAnalyticsSettings(int $userId): array
	{
		return [
			'userType' => $this->analyticsService->getUserTypeParameter($userId),
			'isDemo' => $this->tariffService->isDemo(),
		];
	}

	public function getTaskCardSettings(int $userId): array
	{
		return [
			'userId' => $userId,
			'formV2Enabled' => FormV2Feature::isOn(),
			'userTaskPath' => RouteDictionary::PATH_TO_USER_TASK,
			'groupTaskPath' => RouteDictionary::PATH_TO_GROUP_TASK,
			'templatePath' => RouteDictionary::PATH_TO_USER_TEMPLATE,
			'userDetailUrlTemplate' => $this->userUrlService->getDetailUrlTemplate(),
			'hasMandatoryTaskUserFields' => $this->userFieldSchemeRepository->getCollection($userId, Task::getEntityCode())->findOne(['mandatory' => true]) !== null,
			'hasMandatoryTemplateUserFields' => $this->userFieldSchemeRepository->getCollection($userId, Template::getEntityCode())->findOne(['mandatory' => true]) !== null,
		];
	}
}
