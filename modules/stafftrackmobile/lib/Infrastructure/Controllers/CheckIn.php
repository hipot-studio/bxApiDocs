<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\StaffTrack\Controller\Trait\ErrorResponseTrait;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Public\Command\CheckIn\AddBatch;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Command\Office\ConfirmPresence;
use Bitrix\StaffTrack\Public\Provider\ChatProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInFormProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInGeoPermissionProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInProvider;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;
use Bitrix\StaffTrack\Public\Provider\GeoProvider;
use Bitrix\StaffTrack\Public\Services\AddressResolverService;
use Bitrix\StaffTrack\Public\Services\CheckInService;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\CheckInToolEnabled;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\CheckInAvailableByRegionFilter;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\IntranetOnlyFilter;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\NewCheckInEnabledFilter;
use Bitrix\StaffTrackMobile\Public\Features\CheckInV3StaffSelectorFeature;

final class CheckIn extends Base
{
	use ErrorResponseTrait;

	/**
	 * Every spelling a client can refuse the flat list in. The payload of an action travels as a query
	 * string, so a boolean of the client reaches the server as the text `false`, and a `bool` parameter
	 * of the action would read that as consent - the refusal is taken as text instead.
	 */
	private const FLAT_LIST_REFUSALS = ['', '0', 'n', 'false'];

	public function configureActions(): array
	{
		return [];
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new IntranetOnlyFilter(),
			new NewCheckInEnabledFilter(),
			new CheckInAvailableByRegionFilter(),
			new CloseSession(),
		];
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
	 * @ajaxAction stafftrackmobile.v2.CheckIn.add
	 * @param CheckInDto $checkInDto
	 * @return array|null
	 */
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
	 * @ajaxAction stafftrackmobile.v2.CheckIn.batchAdd
	 * @param array $checkIns
	 * @return array|null
	 */
	#[CheckInToolEnabled]
	public function batchAddAction(array $checkIns): ?array
	{
		// The authoritative bound lives in the command, but it is applied once the whole request has been
		// turned into DTOs. Here it costs O(1) and spares the worker an object per element of a request
		// that will be refused anyway. The answer carries the code of the command: whichever of the two
		// bounds fires, the client sees one outcome, and an answer without a code it would repeat as a
		// network failure forever.
		if (count($checkIns) > CheckInService::MAX_BATCH_SIZE)
		{
			$this->addErrors([
				new \Bitrix\Main\Error(
					'Check-in batch size exceeds the limit of ' . CheckInService::MAX_BATCH_SIZE,
					AddBatch::ERROR_BATCH_SIZE,
				),
			]);

			return null;
		}

		$userId = (int)$this->getCurrentUser()?->getId();
		$timeZone = \CTimeZone::GetOffset();

		$dtos = [];
		foreach ($checkIns as $point)
		{
			$dto = CheckInDto::createFromArray((array)$point);
			$dto->setUserId($userId);
			$dto->setUserTimezone($timeZone);

			$dtos[] = $dto;
		}

		$result = CheckInService::getInstance()->addBatch($dtos);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.createChatWithDepartmentHead
	 * @return array
	 */
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
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getUserCheckInByDate
	 * @param int $userId
	 * @param int $timestamp
	 * @param string $withFlatList a bundle that reads only `markers` and `track` refuses the flat list
	 * @return array
	 */
	#[CheckInToolEnabled]
	public function getUserCheckInByDateAction(int $userId, int $timestamp, string $withFlatList = 'Y'): ?array
	{
		if (!$this->canViewEmployee($userId))
		{
			return null;
		}

		$timezoneOffset = \CTimeZone::GetOffset();
		$result = (new CheckInProvider())->getUserCheckInByDate(
			$userId,
			$timestamp,
			$timezoneOffset,
			self::wantsFlatList($withFlatList),
		);
		$result['users'] = UserRepository::getByIds([$userId]);
		$result['isGeoPermissionPostponed'] = $this->isGeoPermissionPostponed($result, $userId);

		$this->enqueueTimelineAddressResolve($result, $userId);

		return $result;
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getMapData
	 * @param int $timestamp
	 * @param array $entities
	 * @return array
	 */
	#[CheckInToolEnabled]
	public function getMapDataAction(int $timestamp = 0, array $entities = []): array
	{
		$provider = new CheckInProvider();
		$timezoneOffset = \CTimeZone::GetOffset();
		$userId = (int)$this->getCurrentUser()?->getId();
		$isStaffSelectorEnabled = (new CheckInV3StaffSelectorFeature())->isEnabled();

		[
			'checkIns' => $checkIns,
			'stats' => $stats,
			'manualStats' => $manualStats,
			'checkInRolloutEnabled' => $checkInRolloutEnabled,
			'subscribedUserIds' => $subscribedUserIds,
		] = $provider->getLatestUsersCheckInByDepartment(
			$timezoneOffset,
			$timestamp,
			$isStaffSelectorEnabled ? $entities : [],
		);

		return [
			'checkIns' => $checkIns,
			'stats' => $stats,
			'manualStats' => $manualStats,
			'checkInRolloutEnabled' => $checkInRolloutEnabled,
			'subscribedUserIds' => $subscribedUserIds,
			'hasSubordinates' => DepartmentProvider::hasSubordinates($userId),
			'users' => UserRepository::getByIds(array_column($checkIns, 'userId')),
			'isWesternPortal' => Feature::isGeoRetentionLimited(),
			'isGeoEncryptionAvailable' => Feature::isGeoEncryptionAvailable(),
			'isCheckInV3StaffSelectorEnabled' => $isStaffSelectorEnabled,
		];
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getEmployeeRoute
	 * @param int $userId
	 * @param int $timestamp
	 * @param string $withFlatList a bundle that reads only `markers` and `track` refuses the flat list
	 * @return array
	 */
	#[CheckInToolEnabled]
	public function getEmployeeRouteAction(int $userId, int $timestamp, string $withFlatList = 'Y'): ?array
	{
		if (!$this->canViewEmployee($userId))
		{
			return null;
		}

		$timezoneOffset = \CTimeZone::GetOffset();
		$result = (new CheckInProvider())->getEmployeeRoute(
			$userId,
			$timestamp,
			$timezoneOffset,
			self::wantsFlatList($withFlatList),
		);

		$this->enqueueTimelineAddressResolve($result, $userId);

		return $result;
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.loadCheckInForm
	 * @param float $latitude
	 * @param float $longitude
	 * @return array|null
	 */
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
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getManualCheckInCount
	 * @return array
	 */
	public function getManualCheckInCountAction(): array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$timezoneOffset = \CTimeZone::GetOffset();

		$count = (new CheckInProvider())
			->getManualCheckInCountByDate($userId, time(), $timezoneOffset)
		;

		return ['count' => $count];
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getAddressByCoordinates
	 * @param float $latitude
	 * @param float $longitude
	 * @return array
	 */
	#[CheckInToolEnabled]
	public function getAddressByCoordinatesAction(float $latitude, float $longitude): array
	{
		$geoProvider = new GeoProvider($latitude, $longitude);
		$result = $geoProvider->getAddress();
		$address = $result->isSuccess() ? ($result->getData()['address'] ?? '') : '';

		return ['address' => $address];
	}

	/**
	 * The signal of a client standing still: the anchor of its stay and the moment of its clock, weighed
	 * as one more point of the employee and stored nowhere. It stands behind the same prefilters and the
	 * same attribute as the acceptance of points, and answers nothing: the client has nothing to do with
	 * the outcome, and every rule of the signal lives in the command.
	 *
	 * @ajaxAction stafftrackmobile.v2.CheckIn.confirmPresence
	 * @param float $latitude
	 * @param float $longitude
	 * @param int $timestamp
	 */
	#[CheckInToolEnabled]
	public function confirmPresenceAction(float $latitude, float $longitude, int $timestamp): void
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		$result = (new ConfirmPresence($userId))->execute($latitude, $longitude, $timestamp);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	/**
	 * The mark is the state the employee is in now and not a record of the day: on a date already over it would
	 * read as an event of that date. So it is asked of the day being lived through only - by the window of the
	 * employee the answer was built on - and only once the viewer has been let into the day; nothing of the mark
	 * but this verdict leaves.
	 *
	 * @param array{isDayLive?: bool} $dayResult the day answer this verdict joins
	 */
	private function isGeoPermissionPostponed(array $dayResult, int $userId): bool
	{
		if (($dayResult['isDayLive'] ?? false) !== true)
		{
			return false;
		}

		return (new CheckInGeoPermissionProvider())->isPostponementInForce($userId);
	}

	/**
	 * The queue takes the markers of the day and the two ends of the route, whatever generation the viewer
	 * is on. A marker is a place the day stopped at, and the ends of the route stand on technical moving
	 * points, which never appear in `markers` - the track is the only path by which those two reach the
	 * queue. Points that already have an address are dropped by queueCheckIns().
	 *
	 * The flat list of the day is never queued, not even for a v2 viewer who draws every point as a row of
	 * its own. An address cell is a geohash of about 38 by 19 metres, so a moving employee leaves a cell
	 * per point, and one reading of one day would charge the portal a call to the geo service for each of
	 * them - a thousand of them on a day of travel, against the few a day stops at. The geo service is
	 * shared by the whole portal, and this is a read anyone can repeat at will.
	 *
	 * @param array{markers?: array<int, array>, track?: array<int, array>} $dayResult
	 */
	private function enqueueTimelineAddressResolve(array $dayResult, int $userId): void
	{
		if (!Feature::isGeoEncryptionAvailable())
		{
			return;
		}

		// the provider answers without markers or track when it has no viewer (CheckInProvider::getUserCheckInByDate)
		$points = array_merge(
			$dayResult['markers'] ?? [],
			self::collectRouteEnds($dayResult['track'] ?? [], $userId),
		);
		if (empty($points))
		{
			return;
		}

		$viewerId = (int)$this->getCurrentUser()?->getId();
		$resolver = new AddressResolverService();

		// the queue is filled before the answer leaves: a background job of this hit may never run,
		// and then the points would never reach the queue at all
		$resolver->queueCheckIns($points, $viewerId);

		// the resolving itself costs seconds of geo service calls, so it stays behind the answer, and it
		// runs even when the queue took nothing new: a repeat of the same day queues nothing, yet the
		// points of the earlier hit may still be waiting. A pass with nothing to do costs one attempt at
		// the lock, a skipped one costs the addresses of the day for good
		Application::getInstance()->addBackgroundJob(
			static fn() => $resolver->resolveQueued(),
		);
	}

	/**
	 * @param array<int, array{id: int, time: int, address: string}> $track
	 * @return array<int, array>
	 */
	private static function collectRouteEnds(array $track, int $userId): array
	{
		if (empty($track))
		{
			return [];
		}

		$ends = [$track[array_key_first($track)]];
		if (count($track) > 1)
		{
			$ends[] = $track[array_key_last($track)];
		}

		return array_map(
			static fn(array $point): array => $point + ['userId' => $userId],
			$ends,
		);
	}

	private static function wantsFlatList(string $withFlatList): bool
	{
		return !in_array(mb_strtolower($withFlatList), self::FLAT_LIST_REFUSALS, true);
	}

	private function canViewEmployee(int $targetUserId): bool
	{
		$currentUserId = (int)$this->getCurrentUser()?->getId();
		if (DepartmentProvider::isAccessibleEmployee($currentUserId, $targetUserId))
		{
			return true;
		}

		$this->addError(new \Bitrix\Main\Error('Access denied', 403));

		return false;
	}
}
