<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\StaffTrack\Infrastructure\ActionFilters\OfficeUnavailableFilter;
use Bitrix\StaffTrack\Internal\Integration\Pull\Tag;
use Bitrix\StaffTrack\Internal\Service\CheckIn\RouteWebPayload;
use Bitrix\StaffTrack\Item\User;
use Bitrix\StaffTrack\Provider\UserProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInProvider;
use Bitrix\StaffTrack\Public\Services\DayAddressQueue;
use Bitrix\StaffTrack\Public\Services\EmployeeRouteAccessService;
use Bitrix\StaffTrack\Public\Services\PointSnapshot;

class CheckInRoute extends Controller
{
	protected const ACCESS_DENIED = 'STAFFTRACK_ROUTE_ACCESS_DENIED';

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new OfficeUnavailableFilter(),
		];
	}

	public function configureActions(): array
	{
		return [
			'get' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getLastPointSnapshot' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getAction(int $employeeId): ?array
	{
		$viewerId = (int)CurrentUser::get()->getId();
		if (!$this->canViewRoute($viewerId, $employeeId))
		{
			$this->addError(new Error('Access denied', self::ACCESS_DENIED));

			return null;
		}

		$day = $this->getCheckInProvider()->getUserCheckInByDate(
			$employeeId,
			time(),
			$this->getTimezoneOffset($employeeId),
			withFlatList: false,
		);

		$this->getDayAddressQueue()->enqueue($day, $employeeId, $viewerId);

		return RouteWebPayload::format($day, $this->getEmployee($employeeId), Tag::getUserTag($viewerId));
	}

	/**
	 * @return array{snapshotUrl: string|null}|null
	 */
	public function getLastPointSnapshotAction(int $employeeId): ?array
	{
		$viewerId = (int)CurrentUser::get()->getId();
		if (!$this->canViewRoute($viewerId, $employeeId))
		{
			$this->addError(new Error('Access denied', self::ACCESS_DENIED));

			return null;
		}

		$point = $this->getCheckInProvider()->getLatestDayPoint(
			$employeeId,
			$this->getTimezoneOffset($employeeId),
		);

		if ($point === null)
		{
			return ['snapshotUrl' => null];
		}

		return [
			'snapshotUrl' => $this->getPointSnapshot()->renderUrl((float)$point['lat'], (float)$point['lon']),
		];
	}

	protected function canViewRoute(int $viewerId, int $employeeId): bool
	{
		return $employeeId > 0
			&& $this->isIntranetUser($viewerId)
			&& $employeeId !== $viewerId
			&& $this->isActiveEmployee($employeeId)
			&& $this->isAccessibleEmployee($viewerId, $employeeId)
		;
	}

	protected function isIntranetUser(int $userId): bool
	{
		return $this->getRouteAccessService()->isIntranetUser($userId);
	}

	protected function isActiveEmployee(int $employeeId): bool
	{
		return $this->getRouteAccessService()->isActiveEmployee($employeeId);
	}

	protected function isAccessibleEmployee(int $viewerId, int $employeeId): bool
	{
		return $this->getRouteAccessService()->isAccessibleEmployee($viewerId, $employeeId);
	}

	protected function getTimezoneOffset(int $employeeId): int
	{
		return $this->getRouteAccessService()->getTimezoneOffset($employeeId);
	}

	protected function getRouteAccessService(): EmployeeRouteAccessService
	{
		return new EmployeeRouteAccessService();
	}

	/**
	 * @return array{id: int, name: string, workPosition: string, avatar: string}
	 */
	protected function getEmployee(int $employeeId): array
	{
		$users = UserProvider::getInstance()->getUsers([$employeeId])->getValues();
		$user = $users[0] ?? null;

		if (!$user instanceof User)
		{
			return ['id' => $employeeId, 'name' => '', 'workPosition' => '', 'avatar' => ''];
		}

		return [
			'id' => $user->id,
			'name' => $user->name,
			'workPosition' => $user->workPosition,
			'avatar' => $user->avatar,
		];
	}

	protected function getCheckInProvider(): CheckInProvider
	{
		return new CheckInProvider();
	}

	protected function getDayAddressQueue(): DayAddressQueue
	{
		return new DayAddressQueue();
	}

	protected function getPointSnapshot(): PointSnapshot
	{
		return new PointSnapshot();
	}
}
