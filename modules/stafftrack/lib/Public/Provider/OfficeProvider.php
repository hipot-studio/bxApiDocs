<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Access\OfficeCustody;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\EmployeeListVisibility;
use Bitrix\StaffTrack\Internal\Service\Office\EmployeeService;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeItemMapper;
use Bitrix\StaffTrack\Item\Collection\OfficeCollection;
use Bitrix\StaffTrack\Item\Collection\OfficeEmployeeCollection;
use Bitrix\StaffTrack\Item\Office;

/**
 * Every reading takes the viewer it is done for: the staff of an office is shown by name to the viewer
 * {@see EmployeeListVisibility} lets in and to nobody else, so no reading of it goes without one.
 */
class OfficeProvider
{
	private OfficeRepository $officeRepository;
	private OfficeItemMapper $officeItemMapper;
	private EmployeeService $employeeService;
	private EmployeeListVisibility $employeeListVisibility;

	public function __construct()
	{
		$this->officeRepository = new OfficeRepository();
		$this->officeItemMapper = new OfficeItemMapper();
		$this->employeeService = new EmployeeService();
		$this->employeeListVisibility = new EmployeeListVisibility();
	}

	public function getList(int $viewerId): OfficeCollection
	{
		return $this->officeItemMapper->mapList($this->officeRepository->getList(), $viewerId);
	}

	public function getById(int $officeId, int $viewerId): ?Office
	{
		$office = $this->officeRepository->getById($officeId);
		if ($office === null)
		{
			return null;
		}

		return $this->officeItemMapper->map($office, $viewerId);
	}

	public function exists(int $officeId): bool
	{
		return $this->officeRepository->getById($officeId) !== null;
	}

	/**
	 * A page of the staff of an office, continued by the cursor the previous one gave out; zero starts
	 * the list. The card of the office is not built along the way - the links of its photos are reached
	 * for in the disk, and no name on this page needs them.
	 *
	 * A viewer the rule of visibility does not let in is refused with the code of access, and the page
	 * is not read for them.
	 *
	 * @return Result data: array{
	 *     employees: OfficeEmployeeCollection,
	 *     nextAfterUserId: int,
	 *     hasMore: bool,
	 * }
	 */
	public function getEmployeesPage(int $officeId, int $viewerId, int $afterUserId = 0): Result
	{
		$result = new Result();

		if (!$this->employeeListVisibility->isVisibleTo($officeId, $viewerId))
		{
			return $result->addError(
				new Error('Access to the staff of the office denied', OfficeErrorCode::ACCESS_DENIED),
			);
		}

		return $result->setData($this->employeeService->getPage($officeId, $afterUserId));
	}

	/**
	 * Whether the viewer may add an office: the circle of custody, asked apart from the directory so that
	 * the answer does not depend on what the directory holds.
	 */
	public function canCreateOffice(int $userId): bool
	{
		return $this->getOfficeCustody()->isMember($userId);
	}

	protected function getOfficeCustody(): OfficeCustody
	{
		return OfficeCustody::getInstance();
	}
}
