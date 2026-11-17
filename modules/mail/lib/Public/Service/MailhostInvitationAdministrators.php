<?php

namespace Bitrix\Mail\Public\Service;

use Bitrix\Mail\Helper\Mailbox\MailboxConnectionRequestService;

final class MailhostInvitationAdministrators
{
	/**
	 * Returns active confirmed administrators for Mailhost invitations.
	 *
	 * @return int[]
	 */
	public function getIds(): array
	{
		$service = new MailboxConnectionRequestService();
		$responsibleId = $service->getResponsibleAdminId();
		if ($responsibleId > 0 && $this->isAdministrator($responsibleId))
		{
			$responsibleIds = $service->getResponsible();
			if ($responsibleIds === [$responsibleId])
			{
				return $responsibleIds;
			}
		}

		return $service->getActiveAdminIds();
	}

	private function isAdministrator(int $userId): bool
	{
		$groups = \CUser::GetUserGroupEx($userId);
		while ($group = $groups->fetch())
		{
			if ((int)$group['GROUP_ID'] === 1)
			{
				return true;
			}
		}

		return false;
	}
}
