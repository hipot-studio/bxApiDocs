<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Feature\SecuritySettingsFeature;
use Bitrix\Mobile\Feature\WhatsNewFeature;
use Bitrix\Mobile\Menu\MenuList;
use Bitrix\Mobile\Menu\AhaMoment;
use Bitrix\Mobile\Menu\Service\MenuListCache;
use Bitrix\Mobile\Menu\Service\SupportBanners;
use Bitrix\Mobile\Menu\MyCompany\BlockProvider;
use Bitrix\Mobile\Menu\MyCompany\Card\EmployeesCardProvider;
use Bitrix\Mobile\Menu\MyCompany\Card\PartnersCardProvider;
use Bitrix\Mobile\Menu\MyCompany\Card\SupportCardProvider;
use Bitrix\Mobile\Menu\MyCompany\Dto\MyCompanyData;
use Bitrix\Mobile\Menu\MyCompany\MyCompanyProvider;
use Bitrix\Mobile\Menu\Promo\FloatingPromoProvider;
use Bitrix\Mobile\Menu\Tariff\TariffPlateProvider;
use Bitrix\Mobile\Provider\CommonUserDto;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Mobile\Provider\ThemeProvider;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\QrLoginFeature;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;
use Bitrix\StaffTrack\Public\Provider\CheckInProvider;
use Bitrix\Mobile\Menu\ToolUsage\ToolUsage;

class Menu extends Controller
{
	/**
	 * @var array<int, MyCompanyProvider>
	 */
	private array $myCompanySections = [];

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
			'getImplementationSpecialistsList' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getMyCompany' => [
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

		$checkInData = $this->getCheckInData($user);

		$canUseMyCompany = $this->canUseMyCompany($user);
		$canInvite = $this->canInvite($user);

		$employees = new EmployeesCardProvider($user);

		$response = [
			'user' => $user,
			'menuList' => $user->isGuest ? [] : (new MenuList($context, $cache))->build($forceRefresh),
			'currentShift' => $this->getCurrentShift($user),
			'portal' => $this->getPortal($user, $canUseMyCompany),
			'supportBanners' => $this->getSupportBanners(),
			'ahaMoment' => $this->getAhaMoment($user, $canInvite),
			'currentTheme' => (new ThemeProvider($userId))->getCurrentTheme(),
			'isNewCheckInEnabled' => $checkInData['isNewCheckInEnabled'],
			'manualCheckIns' => $checkInData['manualCheckIns'],
			'shouldShowPresetBanner' => (new ToolUsage())->shouldShowMenuBanner(),

			'restrictions' => [
				'canOpenProfile' => !$user->isGuest,
				'canEditProfile' => $USER->CanDoOperation('edit_own_profile'),
				'canUseTimeMan' => $this->canUseTimeMan($context),
				'canUseCheckIn' => $checkInData['canUseCheckIn'],
				'canUseMyCompany' => $canUseMyCompany,
				'canInvite' => $canInvite,
				// The call intent is read in either state of the section: while it is off the employees card
				// of the menu carries it, and while it is on the invite deeplink still hands it to the list
				// of users it opens.
				'canUseTelephony' => $employees->canUseTelephony(),
				'canUseSecuritySettings' => $user->isGuest ? false : Feature::isEnabled(SecuritySettingsFeature::class),
				'canUseTabPresetSettings' => !$user->isGuest,
				'canUseNotificationSettings' => !$user->isGuest,
				'canGoToWeb' => !$user->isGuest,
				// Read in either state of the section: while it is off the menu draws the card of the news
				// itself, and while it is on the counter of the entry plate sums the news up.
				'shouldShowWhatsNew' => Feature::isEnabled(WhatsNewFeature::class),
				'canLoginOnOtherDevice' => !$user->isGuest && Feature::isEnabled(QrLoginFeature::class),
			],
		];

		if ($canUseMyCompany)
		{
			$response['company'] = [
				'tariffPlate' => $this->getTariffDemoPlate(),
				'floatingPromo' => $this->getFloatingPromo($user),
			];
		}
		else
		{
			$response['company'] = [
				'employees' => $employees->getCompany(),
			];

			// The destinations of the support card: with the section on the card lives there and takes them
			// from the answer of the section, so nobody reads these in the menu.
			$support = new SupportCardProvider();
			$supportBotId = $support->getBotId();

			$response['helpdeskUrl'] = $support->getHelpdeskUrl();
			$response['supportBotId'] = $supportBotId;
			$response['restrictions']['canUseSupport'] = $supportBotId > 0;
		}

		return $response;
	}

	/**
	 * The whole "My company" section, requested when the section opens and on the refresh gesture:
	 * which cards there are, in which order, and the data of every one of them. The screen is drawn
	 * from this answer alone, so a card waits for nothing of its own. Unavailable section is a field
	 * of the answer, not an error: everything else is simply absent then.
	 *
	 * @restMethod mobile.Menu.getMyCompany
	 */
	public function getMyCompanyAction(): array
	{
		$unavailable = ['available' => false];

		if (!Loader::includeModule('mobileapp'))
		{
			return $unavailable;
		}

		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$userId)
		{
			return $unavailable;
		}

		$user = $this->getUserData($userId);
		if (!$user)
		{
			return $unavailable;
		}

		$provider = $this->getMyCompanySection($user);
		if (!$provider->isAvailable())
		{
			return $unavailable;
		}

		$blocks = new BlockProvider($user, $provider);

		return MyCompanyData::make([
			'available' => true,
			'blocks' => $blocks->getCodes(),
			'blockData' => $blocks->getData(),
			'portal' => $provider->getPortal(),
			'tariffShowcase' => TariffPlateProvider::isShowcaseEnabled(),
		])->toArray();
	}

	private function getCheckInData(CommonUserDto $user): ?array
	{
		$isNewCheckInEnabled = false;
		$manualCheckIns = [];
		$canUseCheckIn = $this->canUseCheckIn($user);

		if ($canUseCheckIn)
		{
			$isNewCheckInEnabled =  Feature::isEnabled(CheckInFeature::class);
			$manualCheckIns = $isNewCheckInEnabled
				? (new CheckInProvider())->getCurrentUserManualCheckInsByDate($user->id, time())
				: [];
		}

		return [
			'isNewCheckInEnabled' => $isNewCheckInEnabled,
			'manualCheckIns' => $manualCheckIns,
			'canUseCheckIn' => $canUseCheckIn,
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
			'EXTERNAL_AUTH_ID' => $userData['EXTERNAL_AUTH_ID'],
		];

		return UserRepository::createUserDto($user);
	}

	private function getCurrentShift(CommonUserDto $user): ?\Bitrix\StaffTrack\Model\Shift
	{
		if (!$this->canUseCheckIn($user))
		{
			return null;
		}

		$offset = \CTimeZone::GetOffset();
		$date = \Bitrix\Main\Type\Date::createFromTimestamp(time() + $offset);
		$dateFormatted = $date->format('d.m.Y');

		return \Bitrix\StaffTrack\Provider\ShiftProvider::getInstance($user->id)->findByDate($dateFormatted);
	}

	private function canUseCheckIn(CommonUserDto $user): bool
	{
		if ($user->isCollaber || $user->isExtranet || $user->isGuest)
		{
			return false;
		}

		return Loader::includeModule('stafftrack')
			&& \Bitrix\StaffTrack\Feature::isCheckInEnabled()
			&& \Bitrix\StaffTrack\Feature::isGeoFeaturesAvailableByRegion();
	}

	private function getSupportBanners(): array
	{
		$service = new SupportBanners();

		return [
			'shouldShow' => $service->shouldShowSupportBanners(),
			'formCode' => $service->getFormCode(),
		];
	}

	/**
	 * The narrow set of the demo plate - the one card of the tariff domain the menu keeps beside the
	 * entry of the section. The full set belongs to the answer of the section.
	 */
	private function getTariffDemoPlate(): array
	{
		return (new TariffPlateProvider())->getDemoPlateData();
	}

	/**
	 * Portal line of the menu answer. The only reader is the plate that opens the "My company"
	 * section: it shows the company name on its main row, so the menu carries the name alone - the
	 * address and the theme of the portal line belong to the section answer.
	 *
	 * Without the section there is no plate either, so the name is not looked up at all.
	 *
	 * @return array{name: string}
	 */
	private function getPortal(CommonUserDto $user, bool $canUseMyCompany): array
	{
		return [
			'name' => $canUseMyCompany ? $this->getMyCompanySection($user)->getPortalName() : '',
		];
	}

	private function getFloatingPromo(CommonUserDto $user): array
	{
		return (new FloatingPromoProvider($user))->getData();
	}

	/**
	 * Whether the user may invite. The signal lays out the employees card of the "My company"
	 * section as well as the menu, and the skeleton the section draws has to match the height of
	 * the loaded card, so both take it from the one place that owns it.
	 */
	private function canInvite(CommonUserDto $user): bool
	{
		return $this->getMyCompanySection($user)->canInvite();
	}

	private function canUseTimeMan(Context $context): bool
	{
		return !$context->extranet
			&& Loader::includeModule('intranet')
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('worktime')
			&& Loader::includeModule('timeman')
			&& \CTimeMan::CanUse()
		;
	}

	/**
	 * Full specialists list data (users + per-user companion) fetched lazily when the list opens,
	 * so the heavy per-user companion loop stays off the menu payload.
	 *
	 * @restMethod mobile.Menu.getImplementationSpecialistsList
	 */
	public function getImplementationSpecialistsListAction(): array
	{
		$empty = ['users' => [], 'extra' => []];

		if (!Loader::includeModule('mobileapp'))
		{
			return $empty;
		}

		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$userId)
		{
			return $empty;
		}

		// The list is a surface of the section, so it answers for the section first and for the tile
		// after - the same order the answer of the section itself follows.
		$user = $this->getUserData($userId);
		if (
			!$user
			|| !$this->canUseMyCompany($user)
			|| !(new PartnersCardProvider($user))->passesSpecialistsGate()
		)
		{
			return $empty;
		}

		global $USER;
		if (!$USER->CanDoOperation('edit_all_users'))
		{
			return $empty;
		}

		// Same order as the section: the sandbox substitutes the list data, not a gate in front of it.
		$sandbox = PartnersCardProvider::createDevSandbox($userId);
		if ($sandbox !== null)
		{
			return $sandbox->getListData();
		}

		return (new \Bitrix\IntranetMobile\Provider\ImplementationSpecialistProvider())->getListData();
	}

	/**
	 * "My company" section availability: the rollout flag plus the company employee check, both
	 * owned by the section provider. The menu carries the flag only: the section data comes from
	 * its own action.
	 *
	 * The very same value also picks the shape of the tariffPlate block, so the menu computes it
	 * once per request and passes the result around instead of asking the provider twice.
	 */
	private function canUseMyCompany(CommonUserDto $user): bool
	{
		return $this->getMyCompanySection($user)->isAvailable();
	}

	private function getAhaMoment(CommonUserDto $user, bool $canInvite): ?array
	{
		return (new AhaMoment())->getMenuAhaMoment($this->getMyCompanySection($user), $canInvite);
	}

	/**
	 * The "My company" provider of the request. The availability flag, the invite signal, the portal
	 * name and the aha moment all ask it, and it keeps no cache of its own: every constructor builds
	 * the availability set anew.
	 */
	private function getMyCompanySection(CommonUserDto $user): MyCompanyProvider
	{
		return $this->myCompanySections[$user->id] ??= new MyCompanyProvider($user);
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

		$user = $this->getUserData($userId);
		if (!$user)
		{
			return [];
		}

		$context = new Context([
			'userId' => $userId,
			'extranet' => $user->isExtranet,
			'isCollaber' => $user->isCollaber,
		]);

		$cache = new MenuListCache($context->userId);

		return [
			'restrictions' => [
				'canInvite' => $this->canInvite($user),
				'canUseTimeMan' => $this->canUseTimeMan($context),
			],
			'ahaMoment' => (new AhaMoment())->getAvatarAhaMoment($userId),
			'menuList' => $user->isGuest ? [] : (new MenuList($context, $cache))->build(false),
		];
	}

}
