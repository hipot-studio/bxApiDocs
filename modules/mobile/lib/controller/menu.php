<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;

use Bitrix\Main\Loader;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Menu\MenuList;
use Bitrix\Mobile\Menu\AhaMoment;
use Bitrix\Mobile\Menu\Service\MenuListCache;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Mobile\Provider\ThemeProvider;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;

class Menu extends Controller
{
	public function configureActions()
	{
		return [
			'getMenu' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getInitialMenuData' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/*
	 * @restMethod mobile.Menu.getMenu
	 */
	public function getMenuAction(bool $forceRefresh = false): array
	{
		if (!Loader::includeModule('mobileapp'))
		{
			return [];
		}
		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$userId)
		{
			return [];
		}

		$user = $this->getUserData($userId);
		if (!$user)
		{
			return [];
		}

		global $USER;

		$context = new Context([
			'userId' => $userId,
			'extranet' => $user->isExtranet,
			'isCollaber' => $user->isCollaber,
		]);
		$cache = new MenuListCache($context->userId);

		$supportBotId = $this->getSupportBotId();
		$currentTheme = (new ThemeProvider($userId))->getCurrentTheme();

		$canUseTimeMan = $this->canUseTimeMan($context);

		$workTime = [];
		$canManageWorkTimeOnMobile = true;

		if ($canUseTimeMan)
		{
			$workTime = $this->getWorkTime();

			$canManageWorkTimeOnMobile = $this->canManageWorkTimeOnMobile($userId, $forceRefresh);
		}

		return [
			'user' => $user,
			'menuList' => (new MenuList($context, $cache))->build($forceRefresh),
			'currentShift' => $this->getCurrentShift($context, $userId),
			'workTime' => $workTime,
			'company' => $this->getCompany($context),
			'helpdeskUrl' => Loader::includeModule('ui') ? \Bitrix\UI\Util::getHelpdeskUrl(true) : null,
			'supportBotId' => $supportBotId,
			'ahaMoment' => $this->getAhaMoment(),
			'currentTheme' => $currentTheme,

			'restrictions' => [
				'canEditProfile' => $USER->CanDoOperation('edit_own_profile'),
				'canUseTimeMan' => $canUseTimeMan,
				'canUseCheckIn' => $this->canUseCheckIn($context),
				'canUseSupport' => $supportBotId > 0,
				'canInvite' => $this->canInvite(),
				'canUseTelephony' => \Bitrix\Main\Loader::includeModule('voximplant') && \Bitrix\Voximplant\Security\Helper::canCurrentUserPerformCalls(),
				'shouldShowWhatsNew' => \Bitrix\Mobile\Config\Feature::isEnabled(\Bitrix\Mobile\Feature\WhatsNewFeature::class),
				'canManageWorkTimeOnMobile' => $canManageWorkTimeOnMobile,
				'canUseSecuritySettings' => \Bitrix\Mobile\Config\Feature::isEnabled(\Bitrix\Mobile\Feature\SecuritySettingsFeature::class),
			],
		];
	}

	private function getUserData(int $userId): ?\Bitrix\Mobile\Provider\CommonUserDto
	{
		$userData = \CUser::getById($userId)->Fetch();
		if (!$userData || !is_array($userData))
		{
			return null;
		}

		$user = [
			'ID' => $userId,
			'LOGIN' => $userData['LOGIN'],
			'NAME' => $userData['NAME'],
			'LAST_NAME' => $userData['LAST_NAME'],
			'SECOND_NAME' => $userData['SECOND_NAME'],
			'EMAIL' => $userData['EMAIL'] ?? '',
			'WORK_PHONE' => $userData['WORK_PHONE'],
			'WORK_POSITION' => $userData['WORK_POSITION'],
			'PERSONAL_MOBILE' => $userData['PERSONAL_MOBILE'],
			'PERSONAL_PHONE' => $userData['PERSONAL_PHONE'],
			'PERSONAL_GENDER' => $userData['PERSONAL_GENDER'],
			'PERSONAL_PHOTO' => $userData['PERSONAL_PHOTO'],
		];

		return UserRepository::createUserDto($user);
	}

	private function getCurrentShift(Context $context, int $userId): ?\Bitrix\StaffTrack\Model\Shift
	{
		if (
			$context->isCollaber || $context->extranet
			|| !Loader::includeModule('stafftrack')
			||  !\Bitrix\StaffTrack\Feature::isCheckInEnabled()
		)
		{
			return null;
		}

		$offset = \CTimeZone::GetOffset();
		$date = \Bitrix\Main\Type\Date::createFromTimestamp(time() + $offset);
		$dateFormatted = $date->format('d.m.Y');

		return \Bitrix\StaffTrack\Provider\ShiftProvider::getInstance($userId)->findByDate($dateFormatted);
	}

	private function canUseCheckIn(Context $context): bool
	{
		return Loader::includeModule('stafftrack')
			&& \Bitrix\StaffTrack\Feature::isCheckInEnabled()
			&& !$context->isCollaber
			&& !$context->extranet;
	}

	private function getSupportBotId(): int
	{
		if (!\Bitrix\Mobile\Config\Feature::isEnabled(\Bitrix\Mobile\Feature\SupportFeature::class))
		{
			return 0;
		}

		return  (int)(new \Bitrix\Mobile\Provider\SupportProvider())->getBotId();
	}

	private function canInvite(): bool
	{
		return (
			Loader::includeModule('intranet')
			&& Loader::includeModule('intranetmobile')
			&& \Bitrix\Intranet\Invitation::canCurrentUserInvite());
	}

	private function canUseTimeMan(Context $context): bool
	{
		return !$context->extranet
			&& Loader::includeModule('timeman')
			&& \CTimeMan::CanUse()
		;
	}

	private function getCompany(Context $context): ?array
	{
		if (
			!Loader::includeModule('intranet')
			|| !Loader::includeModule('intranetmobile')
			|| !Loader::includeModule('humanresources')
			|| $context->extranet
			|| $context->isCollaber
		)
		{
			return null;
		}

		$departmentProvider = new \Bitrix\IntranetMobile\Provider\DepartmentProvider();

		$departmentUserIds = $departmentProvider->getEmployeesFromUserDepartments($context->userId, 4);

		if (count($departmentUserIds) < 4)
		{
			$allUserIds = $departmentProvider->getAllEmployees(0, 8);

			$userIds = array_unique(array_merge($departmentUserIds, $allUserIds));
			$userIds = array_slice($userIds, 0, 4);
		}
		else
		{
			$userIds = $departmentUserIds;
		}

		$users = UserRepository::getByIds($userIds);

		$totalUsersCount = $departmentProvider->getTotalEmployeeCount();

		return [
			'users' => $users,
			'totalUsersCount' => $totalUsersCount,
			'canInvite' => true,
		];
	}

	private function getWorkTime()
	{
		if (!\CBXFeatures::IsFeatureEnabled('timeman')
			|| !\CModule::IncludeModule('timeman')
			|| !\CTimeMan::CanUse()
		)
		{
			return [];
		}

		if (abs(\CTimeZone::GetOffset()) > BX_TIMEMAN_WRONG_DATE_CHECK)
		{
			return ['ERROR' => 'WRONG_DATE'];
		}

		$runtimeInfo = \CTimeMan::GetRuntimeInfo(false);

		if (empty($runtimeInfo) || !is_array($runtimeInfo))
		{
			return [];
		}

		return $runtimeInfo;
	}

	private function getAhaMoment(): ?array
	{
		return (new AhaMoment())->getMenuAhaMoment();
	}

	public function getInitialMenuDataAction(): array
	{
		if (!Loader::includeModule('mobileapp'))
		{
			return [];
		}
		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$userId)
		{
			return [];
		}


		$context = new Context();
		$cache = new MenuListCache($context->userId);

		return [
			'restrictions' => [
				'canInvite' => $this->canInvite(),
				'canUseTimeMan' => $this->canUseTimeMan($context),
			],
			'ahaMoment' => (new AhaMoment())->getAvatarAhaMoment($userId),
			'menuList' => (new MenuList($context, $cache))->build(false),
		];
	}

	private function canManageWorkTimeOnMobile(int $userId, bool $forceRefresh = false): bool
	{
		$ttl = 60 * 60 * 24 * 7; // week
		$cacheId = "canManage_workTime_on_mobile_{$userId}";
		$cachePath = '/mobile/menu/canManageWorkTimeOnMobile/';
		$cache = \Bitrix\Main\Data\Cache::createInstance();

		if ($forceRefresh)
		{
			$cache->clean($cacheId, $cachePath);
		}

		if ($cache->initCache($ttl, $cacheId, $cachePath))
		{
			$vars = $cache->getVars();
			if (isset($vars['result']))
			{
				return (bool)$vars['result'];
			}

			$cache->clean($cacheId, $cachePath);
		}

		$scheduleRepository = \Bitrix\Timeman\Service\DependencyManager::getInstance()->getScheduleRepository();
		$schedules = $scheduleRepository->findSchedulesByUserId($userId);

		if (empty($schedules))
		{
			$result = true;
		}
		else
		{
			$result = true;
			foreach ($schedules as $schedule)
			{
				if (!Schedule::isDeviceAllowed(ScheduleTable::ALLOWED_DEVICES_MOBILE, $schedule))
				{
					$result = false;
					break;
				}
			}
		}

		if ($cache->startDataCache())
		{
			$cache->endDataCache(['result' => $result]);
		}


		return $result;
	}
}
