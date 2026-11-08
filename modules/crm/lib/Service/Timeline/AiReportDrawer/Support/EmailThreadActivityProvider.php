<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Crm\Activity\Email\Read\ThreadProvider;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityTable;

final class EmailThreadActivityProvider
{
	public function getByActivityId(int $activityId, int $ownerTypeId, int $ownerId): array
	{
		if ($activityId <= 0 || $ownerTypeId <= 0 || $ownerId <= 0)
		{
			return [];
		}

		$activity = ActivityTable::query()
			->setSelect(['THREAD_ID'])
			->where('ID', $activityId)
			->where('PROVIDER_ID', Email::getId())
			->fetch()
		;
		$threadId = (int)($activity['THREAD_ID'] ?? 0);
		if ($threadId <= 0)
		{
			return [];
		}

		return ActivityTable::query()
			->setSelect(['ID', 'START_TIME', 'SETTINGS'])
			->where('THREAD_ID', $threadId)
			->where('BINDINGS.OWNER_TYPE_ID', $ownerTypeId)
			->where('BINDINGS.OWNER_ID', $ownerId)
			->where('PROVIDER_ID', Email::getId())
			->setOrder(['START_TIME' => 'ASC', 'ID' => 'ASC'])
			->setLimit(ThreadProvider::MAX_THREAD)
			->fetchAll()
		;
	}
}
