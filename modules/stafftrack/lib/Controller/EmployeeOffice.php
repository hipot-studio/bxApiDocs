<?php

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Public\Provider\EmployeeOfficeProvider;

/**
 * The card of the office is drawn by the profile without a map: the one that is missing is taken here,
 * in a request of its own, so that no page of a profile ever waits for the map service. The refusal is
 * not an error of the action: a card the viewer may not see and a map that was not taken both answer
 * with no url, because for the client they mean the same - there is no card to draw.
 */
class EmployeeOffice extends Controller
{
	public function configureActions(): array
	{
		return [
			'getSnapshot' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	public function getSnapshotAction(int $employeeId): array
	{
		$card = $this->getEmployeeOfficeProvider()->getProfileCard(
			(int)CurrentUser::get()->getId(),
			$employeeId,
		);

		return [
			'snapshotUrl' => $card->snapshotUrl,
		];
	}

	protected function getEmployeeOfficeProvider(): EmployeeOfficeProvider
	{
		return new EmployeeOfficeProvider();
	}
}
