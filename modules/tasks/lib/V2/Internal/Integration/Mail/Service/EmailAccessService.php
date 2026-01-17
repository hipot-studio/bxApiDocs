<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Mail\Service;

use Bitrix\Mail\Public\Service\Access\MessageAccessService;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Util\UserField\Task;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;

class EmailAccessService
{
	public function canRead(Email $email, int $userId): array
	{
		if (!Loader::includeModule('mail'))
		{
			return [false, null];
		}

		if ($email->taskId <= 0)
		{
			return [false, null];
		}

		$mailUserFieldId = $this->getMailUserFieldId($email, $userId);

		return (new MessageAccessService())->canRead(
			mailboxId: $email->mailboxId,
			messageId: $email->getId(),
			mailUserFieldId: $mailUserFieldId,
			entityId: $email->taskId,
			userId: $userId,
		);
	}

	public function getWithToken(Email $email, array $access, int $userId): Email
	{
		if (!Loader::includeModule('mail'))
		{
			return $email;
		}

		$service = new MessageAccessService();

		$link = $service->getLinkWithToken(
			link: $email->link,
			access: $access,
			userId: $userId,
		);

		return $email->cloneWith(['link' => $link]);
	}

	private function getMailUserFieldId(Email $email, int $userId): int
	{
		global $USER_FIELD_MANAGER;

		$uf = $USER_FIELD_MANAGER->GetUserFields(Task::getEntityCode(), $email->taskId, false, $userId);

		if (!is_array($uf[UserField::TASK_MAIL] ?? null))
		{
			return 0;
		}

		$mailUf = $uf[UserField::TASK_MAIL];

		return (int)($mailUf['ID'] ?? 0);
	}
}
