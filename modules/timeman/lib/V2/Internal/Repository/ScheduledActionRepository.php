<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Entity\ScheduledAction\ScheduledAction;
use Bitrix\Timeman\V2\Internal\Entity\ScheduledAction\ScheduledActionStatus;
use Bitrix\Timeman\V2\Internal\Model\ScheduledActionTable;

final class ScheduledActionRepository
{
	public function getById(int $id): ?ScheduledAction
	{
		$row = ScheduledActionTable::query()
			->addSelect('ID')
			->addSelect('TYPE')
			->addSelect('USER_ID')
			->addSelect('EXECUTE_TIME')
			->addSelect('STATUS')
			->where('ID', $id)
			->setLimit(1)
			->exec()
			->fetch();

		return is_array($row) ? $this->createScheduledActionFromRow($row) : null;
	}

	public function findAction(string $type, int $userId, int $executeTime): ?ScheduledAction
	{
		$row = ScheduledActionTable::query()
			->addSelect('ID')
			->addSelect('TYPE')
			->addSelect('USER_ID')
			->addSelect('EXECUTE_TIME')
			->addSelect('STATUS')
			->where('TYPE', $type)
			->where('USER_ID', $userId)
			->where('EXECUTE_TIME', $executeTime)
			->setLimit(1)
			->exec()
			->fetch();

		return is_array($row) ? $this->createScheduledActionFromRow($row) : null;
	}

	public function add(
		string $type,
		int $userId,
		int $executeTime,
		ScheduledActionStatus $status,
	): Result
	{
		$result = new Result();

		$addResult = ScheduledActionTable::add([
			'TYPE' => $type,
			'USER_ID' => $userId,
			'EXECUTE_TIME' => $executeTime,
			'STATUS' => $status->value,
		]);

		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$result->setData(['id' => (int)$addResult->getId()]);

		return $result;
	}

	public function updateStatus(int $id, ScheduledActionStatus $status): Result
	{
		$result = new Result();

		$updateResult = ScheduledActionTable::update($id, [
			'STATUS' => $status->value,
			'UPDATED_AT' => new DateTime(),
		]);

		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());

			return $result;
		}

		$result->setData(['id' => $id]);

		return $result;
	}

	private function createScheduledActionFromRow(array $row): ScheduledAction
	{
		return new ScheduledAction(
			id: (int)($row['ID'] ?? 0),
			type: (string)($row['TYPE'] ?? ''),
			userId: (int)($row['USER_ID'] ?? 0),
			executeTime: (int)($row['EXECUTE_TIME'] ?? 0),
			status: ScheduledActionStatus::tryFrom((string)($row['STATUS'] ?? ''))
				?? ScheduledActionStatus::Pending,
		);
	}
}
