<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Mail\Repository;

use Bitrix\Mail\Integration\Tasks\TaskMailSourceService;
use Bitrix\Mail\Storage\Message;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Repository\Mapper\EmailMapper;
use Throwable;

class EmailRepository implements EmailRepositoryInterface
{
	public function __construct(
		private readonly EmailMapper $emailMapper,
	)
	{

	}

	public function getByTaskId(int $taskId): ?Email
	{
		if (!Loader::includeModule('mail'))
		{
			return null;
		}

		$email = $this->getByExternalSource($taskId);
		if ($email !== null)
		{
			return $email;
		}

		$row =
			TaskTable::query()
				->setSelect(['ID', UserField::TASK_MAIL])
				->where('ID', $taskId)
				->fetch()
		;

		if (!is_numeric($row[UserField::TASK_MAIL] ?? null))
		{
			return null;
		}

		$mailMessageId = (int)$row[UserField::TASK_MAIL];

		return $this->getById($mailMessageId, $taskId);
	}

	private function getByExternalSource(int $taskId): ?Email
	{
		if (!class_exists(TaskMailSourceService::class))
		{
			return null;
		}

		try
		{
			$emailData = TaskMailSourceService::getEmailDataByTaskId($taskId, withBody: false);
		}
		catch (Throwable)
		{
			return null;
		}

		if (!is_array($emailData))
		{
			return null;
		}

		$emailData['taskId'] = $emailData['taskId'] ?? $taskId;

		return Email::mapFromArray($emailData);
	}

	private function getById(int $id, int $taskId): ?Email
	{
		if (!Loader::includeModule('mail'))
		{
			return null;
		}

		try
		{
			$message = (new Message)->getMessage($id);
		}
		catch (Throwable)
		{
			return null;
		}

		return $this->emailMapper->mapFromEntity($message, $taskId);
	}
}
