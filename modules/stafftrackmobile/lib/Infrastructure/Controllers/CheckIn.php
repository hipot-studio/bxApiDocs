<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\StaffTrack\Controller\Trait\ErrorResponseTrait;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Provider\ChatProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInFormProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInProvider;
use Bitrix\StaffTrack\Public\Provider\GeoProvider;
use Bitrix\StaffTrack\Public\Services\AddressResolverService;
use Bitrix\StaffTrack\Public\Services\CheckInService;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\IntranetOnly;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\NewCheckInEnabled;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\CheckInToolEnabled;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;

final class CheckIn extends Controller
{
	use ErrorResponseTrait;

	public function configureActions(): array
	{
		return [];
	}

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				CheckInDto::class,
				'checkInDto',
				function($className, $checkInDto) {
					return CheckInDto::createFromArray((array)$checkInDto);
				},
			),
		];
	}

	/**
	 * @restMethod stafftrackmobile.v2.CheckIn.add
	 * @param CheckInDto $checkInDto
	 * @return array|null
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	#[CheckInToolEnabled]
	public function addAction(CheckInDto $checkInDto, array $uploadedFiles = []): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$checkInDto->setUserId($userId);

		$timeZone = \CTimeZone::GetOffset();
		$checkInDto->setUserTimezone($timeZone);

		$result = CheckInService::getInstance()->add($checkInDto, $uploadedFiles);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @restMethod stafftrackmobile.v2.CheckIn.createChatWithDepartmentHead
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function createChatWithDepartmentHeadAction(): array
	{
		$chatCreateResult = (new ChatProvider())->createDepartmentHeadChat();

		if (!$chatCreateResult->isSuccess())
		{
			$this->addErrors($chatCreateResult->getErrors());

			return [];
		}

		return $chatCreateResult->getData();
	}

	/**
	 * @restMethod stafftrackmobile.v2.CheckIn.getUserCheckInByDate
	 * @param int $userId
	 * @param int $timestamp
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	#[CheckInToolEnabled]
	public function getUserCheckInByDateAction(int $userId, int $timestamp): ?array
	{
		if (!$this->canViewEmployee($userId))
		{
			return null;
		}

		$timezoneOffset = \CTimeZone::GetOffset();
		$result = CheckInProvider::getInstance()->getUserCheckInByDate($userId, $timestamp, $timezoneOffset);
		$result['users'] = UserRepository::getByIds([$userId]);

		$viewerId = (int)$this->getCurrentUser()?->getId();
		Application::getInstance()->addBackgroundJob(
			static fn() => (new AddressResolverService())->enqueue($result['checkIns'], $viewerId),
		);

		return $result;
	}

	/**
	 * @restMethod stafftrackmobile.v2.CheckIn.getMapData
	 * @param int $timestamp
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	#[CheckInToolEnabled]
	public function getMapDataAction(int $timestamp = 0): array
	{
		$provider = CheckInProvider::getInstance();
		$timezoneOffset = \CTimeZone::GetOffset();
		$userId = (int)$this->getCurrentUser()?->getId();

		['checkIns' => $checkIns, 'stats' => $stats] = $provider->getLatestUsersCheckInByDepartment($timezoneOffset, $timestamp);

		return [
			'checkIns' => $checkIns,
			'stats' => $stats,
			'hasSubordinates' => DepartmentProvider::hasSubordinates($userId),
			'users' => UserRepository::getByIds(array_column($checkIns, 'userId')),
			'isWesternPortal' => Feature::isWesternPortal(),
		];
	}

	/**
	 * @restMethod stafftrackmobile.v2.CheckIn.getEmployeeRoute
	 * @param int $userId
	 * @param int $timestamp
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	#[CheckInToolEnabled]
	public function getEmployeeRouteAction(int $userId, int $timestamp): ?array
	{
		if (!$this->canViewEmployee($userId))
		{
			return null;
		}

		$timezoneOffset = \CTimeZone::GetOffset();

		return CheckInProvider::getInstance()->getEmployeeRoute($userId, $timestamp, $timezoneOffset);
	}

	/**
	 * @restMethod stafftrackmobile.v2.CheckIn.loadCheckInForm
	 * @param float $latitude
	 * @param float $longitude
	 * @return array|null
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function loadCheckInFormAction(float $latitude = 0, float $longitude = 0): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		$formProvider = new CheckInFormProvider();

		$needGeo = ($latitude !== 0.0 && $longitude !== 0.0);
		$result = $needGeo
			? $formProvider->load($userId, $latitude, $longitude)
			: $formProvider->load($userId);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();
		$data['users'] = UserRepository::getByIds([$userId]);

		return $data;
	}

	/**
	 * @restMethod stafftrackmobile.v2.CheckIn.getManualCheckInCount
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function getManualCheckInCountAction(): array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$timezoneOffset = \CTimeZone::GetOffset();

		$count = CheckInProvider::getInstance()
			->getManualCheckInCountByDate($userId, time(), $timezoneOffset)
		;

		return ['count' => $count];
	}

	/**
	 * @restMethod stafftrackmobile.v2.CheckIn.getAddressByCoordinates
	 * @param float $latitude
	 * @param float $longitude
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	#[CheckInToolEnabled]
	public function getAddressByCoordinatesAction(float $latitude, float $longitude): array
	{
		$geoProvider = new GeoProvider($latitude, $longitude);
		$result = $geoProvider->getAddress();
		$address = $result->isSuccess() ? ($result->getData()['address'] ?? '') : '';

		return ['address' => $address];
	}

	private function canViewEmployee(int $targetUserId): bool
	{
		$currentUserId = (int)$this->getCurrentUser()?->getId();
		if ($currentUserId === $targetUserId)
		{
			return true;
		}

		if (DepartmentProvider::isMySubordinate($targetUserId))
		{
			return true;
		}

		$this->addError(new \Bitrix\Main\Error('Access denied', 403));

		return false;
	}
}
