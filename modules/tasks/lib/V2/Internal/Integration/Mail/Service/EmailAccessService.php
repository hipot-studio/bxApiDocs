<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Mail\Service;

use Bitrix\Mail\Integration\Tasks\TaskMailSourceService;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\Public\Service\Access\MessageAccessService;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Util\UserField\Task;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;
use Throwable;

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

		// External sources (crm_activity / mail_message) come from b_mail_task_source and carry a
		// sourceType. Legacy emails linked via UF_MAIL_MESSAGE have no sourceType and use the
		// message-access resolver below.
		if ($email->sourceType !== null)
		{
			return $this->canReadExternalSource($email, $userId);
		}

		if ($email->getId() === null)
		{
			return [false, null];
		}

		$mailUserFieldId = $this->getMailUserFieldId($email, $userId);

		return $this->canReadMessageOfTask(
			$email->mailboxId,
			$email->getId(),
			$mailUserFieldId,
			$email->taskId,
			$userId,
		);
	}

	private function canReadExternalSource(Email $email, int $userId): array
	{
		if ($email->link === null || !class_exists(TaskMailSourceService::class))
		{
			return [false, null];
		}

		try
		{
			$canRead = TaskMailSourceService::canReadSourceByTaskId(
				(int)$email->taskId,
				(int)$email->getSourceId(),
				$userId,
			);
			if (!$canRead)
			{
				return [false, null];
			}

			if (
				$email->sourceType === TaskMailSourceService::SOURCE_TYPE_MAIL_MESSAGE
				&& $email->mailboxId !== null
				&& $email->getId() !== null
			)
			{
				[$canRead, $access] = $this->canReadMessageOfTask(
					$email->mailboxId,
					$email->getId(),
					0,
					(int)$email->taskId,
					$userId,
				);

				return [$canRead, $canRead ? ($access ?? []) : null];
			}

			return [true, []];
		}
		catch (Throwable)
		{
			return [false, null];
		}
	}

	public function getWithToken(Email $email, array $access, int $userId): Email
	{
		if (!Loader::includeModule('mail'))
		{
			return $email;
		}

		if ($email->sourceType !== null)
		{
			try
			{
				$data = TaskMailSourceService::getEmailDataBySource(
					$email->sourceType,
					(int)$email->getSourceId(),
					$userId,
					(int)$email->taskId,
				);
				if (is_array($data))
				{
					$email = Email::mapFromArray($data);
				}
			}
			catch (Throwable)
			{
				return $email;
			}

			if ($email->sourceType !== TaskMailSourceService::SOURCE_TYPE_MAIL_MESSAGE || $access === [])
			{
				return $email;
			}
		}

		$service = new MessageAccessService();

		$link = $service->getLinkWithToken(
			link: $email->link,
			access: $access,
			userId: $userId,
		);

		return $email->cloneWith(['link' => $link]);
	}

	/**
	 * Single gate to the message access check of mail.
	 *
	 * The binding type is pinned: without it a binding of another entity whose id equals the task id
	 * would grant access to the email. The parameter arrived with the mail release of the task email
	 * source, so on an older mail the check is made without it - the same behaviour the whole task
	 * email panel had before that release - instead of failing on an unknown named parameter.
	 */
	private function canReadMessageOfTask(
		int $mailboxId,
		int $messageId,
		int $mailUserFieldId,
		int $taskId,
		int $userId,
	): array
	{
		$service = new MessageAccessService();

		if ((new \ReflectionMethod($service, 'canRead'))->getNumberOfParameters() < 6)
		{
			return $service->canRead($mailboxId, $messageId, $mailUserFieldId, $taskId, $userId);
		}

		return $service->canRead(
			mailboxId: $mailboxId,
			messageId: $messageId,
			mailUserFieldId: $mailUserFieldId,
			entityId: $taskId,
			userId: $userId,
			entityType: MessageAccessTable::ENTITY_TYPE_TASKS_TASK,
		);
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
