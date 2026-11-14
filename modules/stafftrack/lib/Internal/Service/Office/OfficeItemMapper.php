<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Access\Model\OfficeModel;
use Bitrix\StaffTrack\Access\OfficeAccessController;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Access\OfficeCustody;
use Bitrix\StaffTrack\Dictionary\OfficeAccessRequestStatus;
use Bitrix\StaffTrack\Internal\Model\Office;
use Bitrix\StaffTrack\Internal\Repository\OfficeAccessRequestRepository;
use Bitrix\StaffTrack\Item;

/**
 * Turns a row of the office table into the office a client sees. The shape of the answer was released
 * before the storage of the office existed, so the mapping is fixed by that shape and not by the
 * columns.
 *
 * Photos come ready from {@see PhotoService} and the staff from {@see EmployeeService}: both are built
 * elsewhere, and a whole directory asks for what it needs once rather than office by office.
 *
 * A card and a directory carry different amounts of the staff: the card holds its first page, the
 * directory only the numbers. A page per office would cost a read of the records of the people per
 * office, and no client draws those names in a directory anyway.
 *
 * The card holds its page for the viewer {@see EmployeeListVisibility} lets in; anyone else is answered
 * with the numbers alone, as a directory is, and neither the staff nor their presence is assembled for
 * them.
 *
 * Only a card closed to its viewer tells them where their request to see the staff stands: none, pending
 * or rejected. An annulled request reads as none, since nothing keeps the viewer from asking again. An
 * open card and a directory carry no status, and the request is not read for them.
 *
 * Whether the viewer may edit an office is answered by the circle of custody {@see OfficeCustody} - the
 * same predicate the rule of editing stands on, asked directly and not through the access controller
 * and its events. The answer does not depend on the office, so a directory asks it once before the
 * offices are walked.
 *
 * Whether the viewer may delete an office is the answer of the rule of removal, asked through the access
 * controller. It does not depend on the office either and is asked once per answer the same way.
 */
class OfficeItemMapper
{
	private const NO_ACCESS_REQUEST = 'none';

	private PhotoService $photoService;
	private EmployeeService $employeeService;
	private EmployeeListVisibility $employeeListVisibility;
	private OfficeAccessRequestRepository $officeAccessRequestRepository;

	public function __construct()
	{
		$this->photoService = new PhotoService();
		$this->employeeService = new EmployeeService();
		$this->employeeListVisibility = new EmployeeListVisibility();
		$this->officeAccessRequestRepository = new OfficeAccessRequestRepository();
	}

	/**
	 * @param Office[] $offices
	 */
	public function mapList(array $offices, int $viewerId): Item\Collection\OfficeCollection
	{
		$officeIds = array_map(
			static fn (Office $office): int => (int)$office->getId(),
			$offices,
		);

		$photosByOffice = $this->loadPhotosByOfficeIds($officeIds);
		$countsByOffice = $this->loadEmployeeCountsByOfficeIds($officeIds);
		$canEdit = $this->isCustodian($viewerId);
		$canDelete = $this->canDeleteOffices($viewerId);

		return new Item\Collection\OfficeCollection(array_map(
			fn (Office $office): Item\Office => $this->build(
				$office,
				$photosByOffice[(int)$office->getId()] ?? new Item\Collection\OfficePhotoCollection([]),
				$countsByOffice[(int)$office->getId()] ?? 0,
				null,
				$canEdit,
				$canDelete,
			),
			$offices,
		));
	}

	public function map(Office $office, int $viewerId): Item\Office
	{
		$officeId = (int)$office->getId();

		$photos = $this->loadPhotos($officeId);
		$assignedCount = $this->loadEmployeeCount($officeId);
		$employees =
			$this->isEmployeeListVisibleTo($officeId, $viewerId)
				? $this->loadFirstEmployeePage($officeId)
				: null
		;

		return $this->build(
			$office,
			$photos,
			$assignedCount,
			$employees,
			$this->isCustodian($viewerId),
			$this->canDeleteOffices($viewerId),
			$employees === null ? $this->getAccessRequestStatus($officeId, $viewerId) : null,
		);
	}

	protected function isEmployeeListVisibleTo(int $officeId, int $viewerId): bool
	{
		return $this->employeeListVisibility->isVisibleTo($officeId, $viewerId);
	}

	protected function isCustodian(int $viewerId): bool
	{
		return OfficeCustody::getInstance()->isMember($viewerId);
	}

	protected function canDeleteOffices(int $viewerId): bool
	{
		return OfficeAccessController::getInstance($viewerId)
			->check(OfficeAction::DELETE, OfficeModel::createFromId(0))
		;
	}

	protected function loadPhotos(int $officeId): Item\Collection\OfficePhotoCollection
	{
		return $this->photoService->getPhotos($officeId);
	}

	/**
	 * @param int[] $officeIds
	 * @return array<int, Item\Collection\OfficePhotoCollection>
	 */
	protected function loadPhotosByOfficeIds(array $officeIds): array
	{
		if ($officeIds === [])
		{
			return [];
		}

		return $this->photoService->getPhotosByOfficeIds($officeIds);
	}

	protected function loadEmployeeCount(int $officeId): int
	{
		return $this->employeeService->countByOfficeId($officeId);
	}

	/**
	 * @param int[] $officeIds
	 * @return array<int, int> office id => how many employees it has
	 */
	protected function loadEmployeeCountsByOfficeIds(array $officeIds): array
	{
		if ($officeIds === [])
		{
			return [];
		}

		return $this->employeeService->countByOfficeIds($officeIds);
	}

	protected function loadFirstEmployeePage(int $officeId): Item\Collection\OfficeEmployeeCollection
	{
		return $this->employeeService->getPage($officeId)['employees'];
	}

	/**
	 * @return string the stored status of the viewer's request to the office, 'none' when there is none
	 */
	protected function loadAccessRequestStatus(int $officeId, int $viewerId): string
	{
		$request = $this->officeAccessRequestRepository->find($officeId, $viewerId);

		return $request === null ? self::NO_ACCESS_REQUEST : $request['status']->value;
	}

	/**
	 * A grant stored after the visibility was read does not open this card, so it reads as no request.
	 */
	private function getAccessRequestStatus(int $officeId, int $viewerId): string
	{
		$status = $this->loadAccessRequestStatus($officeId, $viewerId);

		return match ($status)
		{
			OfficeAccessRequestStatus::PENDING->value, OfficeAccessRequestStatus::REJECTED->value => $status,
			default => self::NO_ACCESS_REQUEST,
		};
	}

	/**
	 * @param ?Item\Collection\OfficeEmployeeCollection $employees Null when the staff was not assembled.
	 */
	private function build(
		Office $office,
		Item\Collection\OfficePhotoCollection $photos,
		int $assignedCount,
		?Item\Collection\OfficeEmployeeCollection $employees,
		bool $canEdit,
		bool $canDelete,
		?string $accessRequestStatus = null,
	): Item\Office
	{
		return new Item\Office(
			id: (int)$office->getId(),
			title: (string)$office->getTitle(),
			address: $this->buildAddress($office),
			geoZoneRadius: (int)$office->getGeoRadius(),
			photos: $photos,
			assignedCount: $assignedCount,
			// Whether the list was assembled here: the rule of who may see it lives in
			// EmployeeListVisibility, and a card the rule closed carries no list, as a directory does not.
			isEmployeeListAvailable: $employees !== null,
			employees: $employees ?? new Item\Collection\OfficeEmployeeCollection([]),
			canEdit: $canEdit,
			workingHours: $this->buildWorkingHours($office),
			version: OfficeCardVersion::versionOf($office),
			canDelete: $canDelete,
			accessRequestStatus: $accessRequestStatus,
		);
	}

	private function buildAddress(Office $office): Item\OfficeAddress
	{
		return new Item\OfficeAddress(
			value: $office->getAddress() ?? '',
			latitude: (float)$office->getLatitude(),
			longitude: (float)$office->getLongitude(),
		);
	}

	private function buildWorkingHours(Office $office): ?Item\OfficeWorkingHours
	{
		$from = $office->getWorkTimeFrom();
		$to = $office->getWorkTimeTo();

		return Item\OfficeWorkingHours::fromMinutes(
			$from === null ? null : (int)$from,
			$to === null ? null : (int)$to,
		);
	}
}
