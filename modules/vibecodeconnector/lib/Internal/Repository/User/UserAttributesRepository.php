<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\User;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserAttribute;
use Bitrix\Vibecodeconnector\Internal\Model\User\UserAttributesTable;

final class UserAttributesRepository
{
	public function get(int $userId, UserAttribute $attr): mixed
	{
		$row = UserAttributesTable::query()
			->setSelect([$attr->value])
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch();

		if ($row === false)
		{
			return null;
		}

		return $row[$attr->value] ?? null;
	}

	public function set(int $userId, UserAttribute $attr, mixed $value): void
	{
		$now = new DateTime();
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = UserAttributesTable::getTableName();

		[$mergeSql] = $helper->prepareMerge(
			$tableName,
			['USER_ID'],
			['USER_ID' => $userId, 'CREATED_AT' => $now, $attr->value => $value],
			[$attr->value => $value],
		);

		if ($mergeSql !== '')
		{
			$connection->queryExecute($mergeSql);

			return;
		}

		$existing = $this->findId($userId);
		if ($existing !== null)
		{
			UserAttributesTable::update($existing, [$attr->value => $value]);

			return;
		}

		try
		{
			UserAttributesTable::add([
				'USER_ID' => $userId,
				'CREATED_AT' => $now,
				$attr->value => $value,
			]);
		}
		catch (\Throwable $e)
		{
			$existing = $this->findId($userId);
			if ($existing === null)
			{
				throw $e;
			}
			UserAttributesTable::update($existing, [$attr->value => $value]);
		}
	}

	public function exists(int $userId, UserAttribute $attr): bool
	{
		return $this->get($userId, $attr) !== null;
	}

	public function delete(int $userId, UserAttribute $attr): void
	{
		$existing = $this->findId($userId);
		if ($existing === null)
		{
			return;
		}

		UserAttributesTable::update($existing, [$attr->value => null]);
	}

	private function findId(int $userId): ?int
	{
		$row = UserAttributesTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch();

		return $row !== false ? (int)$row['ID'] : null;
	}
}
