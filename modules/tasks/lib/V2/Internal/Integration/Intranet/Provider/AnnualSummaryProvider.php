<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Provider;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\Provider\TaskQuery;

if (!Loader::includeModule('intranet'))
{
	return;
}

class AnnualSummaryProvider implements SummaryProviderInterface
{
	public const TASK_PROVIDER_ID = 'tasks.completed_tasks';
	public const USER_LIMIT = 2;
	private int $lastUserId = 0;

	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
	)
	{
	}

	public function getId(): string
	{
		return self::TASK_PROVIDER_ID;
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_LIMIT;
	}

	public function provide(array $userIds): EntityCollection
	{
		$collection = new EntityCollection();

		try
		{
			foreach ($userIds as $userId)
			{
				$filter = [
					'STATUS' => Status::COMPLETED,
					'RESPONSIBLE_ID' => $userId,
					'>=CLOSED_DATE' => $this->from,
					'<CLOSED_DATE' => $this->to,
				];
				$query = (new TaskQuery($userId))->setWhere($filter);
				$provider = new TaskList();

				$summary = new Summary($userId, $this->getId(), $provider->getCount($query));

				$collection->add($summary);
				$this->lastUserId = $userId;
			}

			return $collection;
		}
		catch (\Exception)
		{
			return $collection;
		}
	}
}
