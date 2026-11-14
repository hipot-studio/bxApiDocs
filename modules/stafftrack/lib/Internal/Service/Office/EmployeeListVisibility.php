<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Access\OfficeCustody;
use Bitrix\StaffTrack\Internal\Repository\OfficeAccessRequestRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;

/**
 * Whether a viewer may see the staff of an office by name: the card of an office and the page of its
 * staff both take the answer from here, so the two cannot drift apart.
 *
 * Three sources are joined by "or", and their order is the point of it. The row of assignment goes first:
 * one read by a unique key, it answers for the assigned employee without the chain of the structure the
 * circle stands on, and "assigned" is the row as it stands, whatever the state of the account. The circle
 * of custody goes second: its answer is remembered per user for the request, while the row is read anew
 * on every call. The access granted on request goes last and is read only for the offices the first two
 * left closed, all of them in one read.
 */
class EmployeeListVisibility
{
	protected OfficeEmployeeRepository $officeEmployeeRepository;
	protected OfficeAccessRequestRepository $officeAccessRequestRepository;

	public function __construct()
	{
		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
		$this->officeAccessRequestRepository = new OfficeAccessRequestRepository();
	}

	public function isVisibleTo(int $officeId, int $viewerId): bool
	{
		return $this->filterVisible([$officeId], $viewerId) !== [];
	}

	/**
	 * @param int[] $officeIds
	 * @return int[]
	 */
	public function filterVisible(array $officeIds, int $viewerId): array
	{
		if ($officeIds === [])
		{
			return [];
		}

		$officeOfViewer = $this->officeEmployeeRepository->getOfficeIdsByUserIds([$viewerId])[$viewerId] ?? null;
		$foreignOfficeIds = array_filter($officeIds, static fn (int $officeId): bool => $officeId !== $officeOfViewer);
		if ($foreignOfficeIds === [] || $this->isCustodian($viewerId))
		{
			return array_values($officeIds);
		}

		$grantedOfficeIds = $this->getGrantedOfficeIds($viewerId, array_values($foreignOfficeIds));
		$closedOfficeIds = array_diff($foreignOfficeIds, $grantedOfficeIds);

		return array_values(array_diff($officeIds, $closedOfficeIds));
	}

	protected function isCustodian(int $viewerId): bool
	{
		return OfficeCustody::getInstance()->isMember($viewerId);
	}

	/**
	 * @param int[] $officeIds
	 * @return int[]
	 */
	protected function getGrantedOfficeIds(int $viewerId, array $officeIds): array
	{
		return $this->officeAccessRequestRepository->getGrantedOfficeIds($viewerId, $officeIds);
	}
}
