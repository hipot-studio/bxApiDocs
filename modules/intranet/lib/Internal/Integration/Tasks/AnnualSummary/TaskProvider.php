<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Tasks\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\TaskFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\Provider\TaskQuery;

class TaskProvider extends AbstractFeatureProvider
{
	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException|LoaderException
	 */
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		$filter = [
			'STATUS' => Status::COMPLETED,
			'RESPONSIBLE_ID' => $userId,
			'>=CLOSED_DATE' => $from,
			'<CLOSED_DATE' => $to,
		];

		$query = (new TaskQuery($userId))->setWhere($filter);
		$provider = new TaskList();

		return $provider->getCount($query);
	}

	public function createFeature(int $value): TaskFeature
	{
		return new TaskFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		return (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_task_count');
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}
}
