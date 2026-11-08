<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Access\OfficeCustody;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;

/**
 * Whether a viewer may see the staff of an office by name: the card of an office and the page of its
 * staff both take the answer from here, so the two cannot drift apart.
 *
 * Two sources are joined by "or", and their order is the point of it. The row of assignment goes first:
 * one read by a unique key, it answers for the assigned employee without the chain of the structure the
 * circle stands on, and "assigned" is the row as it stands, whatever the state of the account. The circle
 * of custody goes second: its answer is remembered per user for the request, while the row is read anew
 * on every call. A further source, should one appear, is a third operand of the same "or", after the circle.
 */
class EmployeeListVisibility
{
	protected OfficeEmployeeRepository $officeEmployeeRepository;

	public function __construct()
	{
		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
	}

	public function isVisibleTo(int $officeId, int $viewerId): bool
	{
		$officeOfViewer = $this->officeEmployeeRepository->getOfficeIdsByUserIds([$viewerId])[$viewerId] ?? null;
		if ($officeOfViewer === $officeId)
		{
			return true;
		}

		return $this->isCustodian($viewerId);
	}

	protected function isCustodian(int $viewerId): bool
	{
		return OfficeCustody::getInstance()->isMember($viewerId);
	}
}
