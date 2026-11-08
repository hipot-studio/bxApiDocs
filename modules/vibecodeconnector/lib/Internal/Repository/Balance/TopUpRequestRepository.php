<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Balance;

use Bitrix\Main\Application;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Vibecodeconnector\Internal\Entity\Balance\TopUpRequestStatus;
use Bitrix\Vibecodeconnector\Internal\Model\Balance\TopUpRequestTable;

class TopUpRequestRepository
{
	public function getByUserId(int $userId): ?array
	{
		$row = TopUpRequestTable::query()
			->setSelect(['ID', 'USER_ID', 'STATUS'])
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $row;
	}

	public function saveClosed(int $userId): void
	{
		try
		{
			$connection = Application::getConnection();
			[$mergeSql] = $connection->getSqlHelper()->prepareMerge(
				TopUpRequestTable::getTableName(),
				['USER_ID'],
				['USER_ID' => $userId, 'STATUS' => TopUpRequestStatus::Closed->value],
				['USER_ID' => $userId],
			);
			$connection->queryExecute($mergeSql);
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException($exception->getMessage(), previous: $exception);
		}
	}

	public function activate(int $requestId): void
	{
		try
		{
			$result = TopUpRequestTable::update($requestId, [
				'STATUS' => TopUpRequestStatus::Active->value,
			]);
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException($exception->getMessage(), previous: $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(implode('; ', $result->getErrorMessages()), errors: $result->getErrors());
		}
	}

	public function closeAllActive(): void
	{
		try
		{
			$rows = TopUpRequestTable::query()
				->setSelect(['ID'])
				->where('STATUS', TopUpRequestStatus::Active->value)
				->fetchAll()
			;
			if ($rows === [])
			{
				return;
			}

			$result = TopUpRequestTable::updateMulti(array_column($rows, 'ID'), [
				'STATUS' => TopUpRequestStatus::Closed->value,
			]);
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException($exception->getMessage(), previous: $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(implode('; ', $result->getErrorMessages()), errors: $result->getErrors());
		}
	}

	public function hasActive(int $userId): bool
	{
		return TopUpRequestTable::query()
			->setSelect(['USER_ID'])
			->where('USER_ID', $userId)
			->where('STATUS', TopUpRequestStatus::Active->value)
			->setLimit(1)
			->fetch() !== false;
	}
}
