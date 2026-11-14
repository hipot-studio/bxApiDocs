<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\StaffTrack\Dictionary\EmployeeOfficeCardStatus;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Item\EmployeeLocationBlock;
use Bitrix\StaffTrack\Item\EmployeeOfficeCard;
use Bitrix\StaffTrack\Provider\UserProvider;
use Bitrix\StaffTrack\Public\Services\EmployeeRouteAccessService;
use Bitrix\StaffTrack\Public\Services\PointSnapshot;

class EmployeeLocationBlockProvider
{
	private const MAP_PAGE_PATH = 'stafftrack/offices/';

	/** @var array<string, bool> */
	private array $routeAvailabilityCache = [];

	/** @var array<int, int> */
	private array $timezoneOffsetCache = [];

	public function getBlock(int $viewerId, int $employeeId, bool $allowSnapshotRender = false): ?EmployeeLocationBlock
	{
		if (!$this->areOfficesAvailable())
		{
			return null;
		}

		if ($this->hasDayCheckInOfEmployee($employeeId) && $this->isRouteAvailable($viewerId, $employeeId))
		{
			$point = $this->getLatestDayPointOfEmployee($employeeId);
			if ($point !== null)
			{
				return $this->makeRouteBlock($employeeId, $point, $allowSnapshotRender, $this->getRouteUrl($employeeId));
			}
		}

		$card = $this->getOfficeCard($viewerId, $employeeId, $allowSnapshotRender);
		if ($card->status !== EmployeeOfficeCardStatus::AVAILABLE || $card->officeId === null)
		{
			return null;
		}

		$isRouteAvailable = $this->isRouteAvailable($viewerId, $employeeId);
		$mapUrl = $isRouteAvailable ? $this->getRouteUrl($employeeId) : null;

		return EmployeeLocationBlock::office(
			$card->officeId,
			$card->title,
			$card->address,
			$card->snapshotUrl,
			$isRouteAvailable,
			$mapUrl ?? $this->getOfficeUrl($card->officeId),
			$card->presence,
		);
	}

	public function hasBlock(int $viewerId, int $employeeId): bool
	{
		if (!$this->areOfficesAvailable())
		{
			return false;
		}

		if (
			$this->getWorkingOfficeId($employeeId) !== null
			&& $this->canViewOfficeCard($viewerId, $employeeId)
		)
		{
			return true;
		}

		return $this->hasDayCheckInOfEmployee($employeeId)
			&& $this->isRouteAvailable($viewerId, $employeeId)
			&& $this->getLatestDayPointOfEmployee($employeeId) !== null
		;
	}

	private function hasDayCheckInOfEmployee(int $employeeId): bool
	{
		return $this->hasDayCheckIn($employeeId, $this->getTimezoneOffsetOfEmployee($employeeId));
	}

	private function getLatestDayPointOfEmployee(int $employeeId): ?array
	{
		return $this->getLatestDayPoint($employeeId, $this->getTimezoneOffsetOfEmployee($employeeId));
	}

	private function getTimezoneOffsetOfEmployee(int $employeeId): int
	{
		return $this->timezoneOffsetCache[$employeeId] ??= $this->getTimezoneOffset($employeeId);
	}

	private function makeRouteBlock(
		int $employeeId,
		array $point,
		bool $allowSnapshotRender,
		string $mapUrl,
	): EmployeeLocationBlock
	{
		$latitude = (float)$point['lat'];
		$longitude = (float)$point['lon'];
		$address = trim((string)($point['address'] ?? ''));
		$time = (int)$point['time'];
		$dateCreate = (string)($point['dateCreate'] ?? '');

		return EmployeeLocationBlock::route(
			$address === '' ? null : $address,
			$time,
			$dateCreate === '' ? null : $dateCreate,
			CheckInNormalizer::zoneOffsetFromDateCreate($time, $dateCreate),
			$allowSnapshotRender
				? $this->renderPointSnapshotUrl($latitude, $longitude)
				: $this->getStoredPointSnapshotUrl($latitude, $longitude),
			$mapUrl,
			$this->getEmployeeAvatarUrl($employeeId),
		);
	}

	protected function isRouteAvailable(int $viewerId, int $employeeId): bool
	{
		return $this->routeAvailabilityCache[$viewerId . ':' . $employeeId]
			??= $this->resolveRouteAvailability($viewerId, $employeeId);
	}

	private function resolveRouteAvailability(int $viewerId, int $employeeId): bool
	{
		if ($viewerId <= 0 || $employeeId <= 0 || $viewerId === $employeeId)
		{
			return false;
		}

		if (!$this->isIntranetUser($viewerId))
		{
			return false;
		}

		if (!$this->isActiveEmployee($employeeId))
		{
			return false;
		}

		return $this->isAccessibleEmployee($viewerId, $employeeId);
	}

	protected function areOfficesAvailable(): bool
	{
		return (new OfficesAvailabilityProvider())->isAvailable();
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

	protected function hasDayCheckIn(int $employeeId, int $timezoneOffset): bool
	{
		return (new CheckInProvider())->hasDayCheckIn($employeeId, $timezoneOffset);
	}

	protected function getLatestDayPoint(int $employeeId, int $timezoneOffset): ?array
	{
		return (new CheckInProvider())->getLatestDayPoint($employeeId, $timezoneOffset, withStatsBackFill: false);
	}

	protected function getOfficeCard(int $viewerId, int $employeeId, bool $allowSnapshotRender): EmployeeOfficeCard
	{
		return (new EmployeeOfficeProvider())->getProfileCard($viewerId, $employeeId, $allowSnapshotRender);
	}

	protected function canViewOfficeCard(int $viewerId, int $employeeId): bool
	{
		return (new EmployeeOfficeProvider())->canViewEmployee($viewerId, $employeeId);
	}

	protected function getStoredPointSnapshotUrl(float $lat, float $lon): ?string
	{
		return (new PointSnapshot())->getStoredUrl($lat, $lon);
	}

	protected function renderPointSnapshotUrl(float $lat, float $lon): ?string
	{
		return (new PointSnapshot())->renderUrl($lat, $lon);
	}

	protected function getEmployeeAvatarUrl(int $employeeId): ?string
	{
		$user = UserProvider::getInstance()->getUsers([$employeeId])->getValues()[0] ?? null;
		$avatarUrl = $user?->avatar ?? '';

		return $avatarUrl === '' ? null : $avatarUrl;
	}

	protected function getWorkingOfficeId(int $employeeId): ?int
	{
		if ($employeeId <= 0)
		{
			return null;
		}

		return (new OfficeEmployeeRepository())->getWorkingOfficeIdByUserId($employeeId);
	}

	protected function getSiteDir(): string
	{
		return defined('SITE_DIR') ? SITE_DIR : '/';
	}

	private function getRouteUrl(int $employeeId): string
	{
		return $this->getSiteDir() . self::MAP_PAGE_PATH . '?route_user_id=' . $employeeId;
	}

	private function getOfficeUrl(int $officeId): string
	{
		return $this->getSiteDir() . self::MAP_PAGE_PATH . '?office_id=' . $officeId;
	}
}
