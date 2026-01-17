<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Mail\Repository;

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
