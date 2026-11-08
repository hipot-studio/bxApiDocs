<?php

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Controller\Trait\ErrorResponseTrait;
use Bitrix\StaffTrack\Controller\Trait\IntranetUserTrait;
use Bitrix\StaffTrack\Integration\Im\ChatService;
use Bitrix\StaffTrack\Provider\UserProvider;
use Bitrix\StaffTrack\Public\Services\MapDataCache;

/**
 * Every action that changes state is restricted to POST by an attribute rather than by an override of
 * getDefaultPreFilters(): the attribute is an EnablePrefilter, so it is appended to the defaults of the
 * base controller (Authentication, Csrf), while an override would replace them. The reading actions keep
 * the default GET|POST.
 */
class Feature extends Controller
{
	use IntranetUserTrait;
	use ErrorResponseTrait;

	protected UserProvider $userProvider;

	/**
	 * @return void
	 */
	protected function init(): void
	{
		parent::init();

		$this->userProvider = UserProvider::getInstance();
	}

	public function isCheckInEnabledAction(): bool
	{
		return \Bitrix\StaffTrack\Feature::isCheckInEnabledBySettings();
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function turnCheckInSettingOnAction(): void
	{
		if ($this->userIsAdmin())
		{
			\Bitrix\StaffTrack\Feature::turnCheckInSettingOn();
		}
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function turnCheckInSettingOffAction(): void
	{
		if ($this->userIsAdmin())
		{
			\Bitrix\StaffTrack\Feature::turnCheckInSettingOff();
		}
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function turnCheckInGeoOnAction(): void
	{
		if ($this->userIsAdmin())
		{
			\Bitrix\StaffTrack\Feature::turnCheckInGeoOn();
		}
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function turnCheckInGeoOffAction(): void
	{
		if ($this->userIsAdmin())
		{
			\Bitrix\StaffTrack\Feature::turnCheckInGeoOff();
		}
	}

	/**
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function turnCheckInRolloutOnAction(): void
	{
		if ($this->userIsAdmin())
		{
			\Bitrix\StaffTrack\Feature::turnCheckInRolloutOn();
			MapDataCache::invalidateAll();
		}
	}

	/**
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function turnCheckInRolloutOffAction(): void
	{
		if ($this->userIsAdmin())
		{
			\Bitrix\StaffTrack\Feature::turnCheckInRolloutOff();
			MapDataCache::invalidateAll();
		}
	}

	/**
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function setCheckInRolloutDepartmentsAction(array $departmentIds): void
	{
		if ($this->userIsAdmin())
		{
			\Bitrix\StaffTrack\Feature::setCheckInRolloutDepartments($departmentIds);
			MapDataCache::invalidateAll();
		}
	}

	/**
	 * @return array{enabled: bool, departments: int[]}|array{} an empty array with an error for a non-admin
	 */
	public function getCheckInRolloutStateAction(): array
	{
		if (!$this->userIsAdmin())
		{
			return $this->buildErrorResponse('Access denied');
		}

		return [
			'enabled' => \Bitrix\StaffTrack\Feature::isCheckInRolloutEnabledForCompany(),
			'departments' => \Bitrix\StaffTrack\Feature::getCheckInRolloutDepartments(),
		];
	}

	/**
	 * @param string $featureName
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function createDepartmentHeadChatAction(string $featureName): array
	{
		$userId = (int)CurrentUser::get()->getId();

		if (!$this->isIntranetUser($userId))
		{
			return $this->buildErrorResponse('Access denied');
		}

		$feature = \Bitrix\StaffTrack\Dictionary\Feature::tryFrom($featureName);
		if (!$feature)
		{
			return $this->buildErrorResponse('Wrong feature name');
		}

		$chatCreateResult = (new ChatService($userId))->createDepartmentHeadChat($feature);
		if (!$chatCreateResult->isSuccess())
		{
			$this->addErrors($chatCreateResult->getErrors());

			return [];
		}

		return $chatCreateResult->getData();
	}

	/**
	 * @return bool
	 */
	private function userIsAdmin(): bool
	{
		$userId = (int)CurrentUser::get()->getId();

		return $this->userProvider->isUserAdmin($userId);
	}
}
