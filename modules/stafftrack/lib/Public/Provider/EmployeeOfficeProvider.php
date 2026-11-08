<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Intranet\User\Access\Model\TargetUserModel;
use Bitrix\Intranet\User\Access\UserAccessController;
use Bitrix\Intranet\User\Access\UserActionDictionary;
use Bitrix\Intranet\Util;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Dictionary\OfficePresence;
use Bitrix\StaffTrack\Internal\Model\Office;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\EmployeeListVisibility;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeSnapshotService;
use Bitrix\StaffTrack\Internal\Service\Office\PresenceService;
use Bitrix\StaffTrack\Item\EmployeeOfficeCard;

class EmployeeOfficeProvider
{
	public function getProfileCard(
		int $viewerId,
		int $employeeId,
		bool $allowSnapshotRender = true,
	): EmployeeOfficeCard
	{
		if (!$this->areOfficesAvailable() || !$this->canViewEmployee($viewerId, $employeeId))
		{
			return EmployeeOfficeCard::unavailable();
		}

		$officeId = $this->getWorkingOfficeId($employeeId);
		if ($officeId === null)
		{
			return EmployeeOfficeCard::noOffice();
		}

		$office = $this->getOffice($officeId);
		if ($office === null)
		{
			return EmployeeOfficeCard::noOffice();
		}

		$latitude = (float)$office->getLatitude();
		$longitude = (float)$office->getLongitude();

		return EmployeeOfficeCard::available(
			$officeId,
			(string)$office->getTitle(),
			$allowSnapshotRender
				? $this->getSnapshotUrl($officeId, $latitude, $longitude)
				: $this->getStoredSnapshotUrl($officeId, $latitude, $longitude),
			$this->getAddress($office),
			$this->readPresence($viewerId, $employeeId, $officeId),
		);
	}

	public function canCaptureSnapshot(int $officeId): bool
	{
		$office = $this->getOffice($officeId);
		if ($office === null)
		{
			return false;
		}

		return $this->canCaptureOfficeSnapshot(
			$officeId,
			(float)$office->getLatitude(),
			(float)$office->getLongitude(),
		);
	}

	protected function canCaptureOfficeSnapshot(int $officeId, float $latitude, float $longitude): bool
	{
		return (new OfficeSnapshotService())->canCapture($officeId, $latitude, $longitude);
	}

	protected function areOfficesAvailable(): bool
	{
		return (new OfficesAvailabilityProvider())->isAvailable();
	}

	protected function canViewEmployee(int $viewerId, int $employeeId): bool
	{
		if ($viewerId <= 0 || $employeeId <= 0)
		{
			return false;
		}

		if (!$this->isIntranetModuleLoaded())
		{
			return false;
		}

		// The office directory is of the company: every other entrance to it turns away a caller from
		// the outside, and the profile of an extranet user would otherwise answer about his own office.
		if (!$this->isIntranetUser($viewerId))
		{
			return false;
		}

		return $this->checkViewAccess($viewerId, $employeeId);
	}

	protected function isIntranetModuleLoaded(): bool
	{
		return Loader::includeModule('intranet');
	}

	protected function isIntranetUser(int $userId): bool
	{
		return Util::isIntranetUser($userId);
	}

	protected function checkViewAccess(int $viewerId, int $employeeId): bool
	{
		return UserAccessController::getInstance($viewerId)
			->check(UserActionDictionary::VIEW, TargetUserModel::createFromId($employeeId))
		;
	}

	protected function getWorkingOfficeId(int $employeeId): ?int
	{
		if ($employeeId <= 0)
		{
			return null;
		}

		return (new OfficeEmployeeRepository())->getWorkingOfficeIdByUserId($employeeId);
	}

	protected function getOffice(int $officeId): ?Office
	{
		return (new OfficeRepository())->getById($officeId);
	}

	protected function getAddress(Office $office): ?string
	{
		$address = trim((string)$office->getAddress());

		return $address === '' ? null : $address;
	}

	protected function getSnapshotUrl(int $officeId, float $latitude, float $longitude): ?string
	{
		return (new OfficeSnapshotService())->getUrl($officeId, $latitude, $longitude);
	}

	protected function getStoredSnapshotUrl(int $officeId, float $latitude, float $longitude): ?string
	{
		return (new OfficeSnapshotService())->getStoredUrl($officeId, $latitude, $longitude);
	}

	protected function readPresence(int $viewerId, int $employeeId, int $officeId): ?OfficePresence
	{
		if ($viewerId === $employeeId || !$this->isEmployeeListVisibleTo($officeId, $viewerId))
		{
			return null;
		}

		return $this->getPresence($officeId, $employeeId);
	}

	protected function isEmployeeListVisibleTo(int $officeId, int $viewerId): bool
	{
		return (new EmployeeListVisibility())->isVisibleTo($officeId, $viewerId);
	}

	protected function getPresence(int $officeId, int $employeeId): OfficePresence
	{
		return (new PresenceService())->getStatuses($officeId, [$employeeId])[$employeeId] ?? OfficePresence::UNKNOWN;
	}
}
