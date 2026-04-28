<?php

namespace Bitrix\Intranet\Update;

use Bitrix\Intranet\Counters\Counter;
use Bitrix\Intranet\Invitation;
use Bitrix\Intranet\User;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\UserTable;

class InvitationCounter extends Stepper
{
	protected static $moduleId = "intranet";
	private int $limit = 100;
	private ?int $totalWaitingInvitation = null;

	private function getUserIds($lastId = 0): array
	{
		return UserTable::query()
			->setSelect(['ID'])
			->where('REAL_USER', 'expr', true)
			->addFilter('>ID', $lastId)
			->setLimit($this->limit)
			->addOrder('ID')
			->fetchCollection()
			->getIdList()
		;
	}

	public function execute(array &$option): bool
	{
		if (empty($option))
		{
			$option["steps"] = 0;
			$option["count"] = 1;
			$option['lastId'] = 0;
		}
		$userIds = $this->getUserIds($option['lastId']);

		foreach ($userIds as $id)
		{
			$user = new User($id);
			$invitationCounter = new Counter(
				Invitation::getInvitedCounterId(),
			);

			$invitationCounterValue = $user->numberOfInvitationsSent();
			$invitationCounter->setValue($user, $invitationCounterValue);
			$waitingCounterValue = 0;
			if ($user->isAdmin())
			{
				if (!$this->totalWaitingInvitation)
				{
					$this->totalWaitingInvitation = (int)\Bitrix\Intranet\UserTable::createInvitedQuery()
						->where('ACTIVE', 'N')->queryCountTotal()
					;
				}
				$waitingCounter = new Counter(Invitation::getWaitConfirmationCounterId());
				$waitingCounter->setValue($user, $this->totalWaitingInvitation);
				$waitingCounterValue = $this->totalWaitingInvitation;
			}

			$total = $waitingCounterValue + $invitationCounterValue;
			$totalCounter = new Counter(Invitation::getTotalInvitationCounterId());
			$totalCounter->setValue($user, $total);
			$option['lastId'] = $id;
		}

		return count($userIds) < $this->limit ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

}