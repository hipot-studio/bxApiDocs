<?php

namespace Bitrix\StaffTrack\Service;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Model\OptionTable;
use Bitrix\StaffTrack\Trait\Singleton;

class OptionService
{
	use Singleton;

	/**
	 * @param int $userId
	 * @param Option $option
	 * @param string $value
	 * @return void
	 */
	public function save(int $userId, Option $option, string $value): void
	{
		$insertFields = [
			'USER_ID' => $userId,
			'NAME' => $option->value,
			'VALUE' => $value
		];

		$updateFields = [
			'VALUE' => $value,
		];

		$uniqueFields = ['USER_ID', 'NAME'];

		OptionTable::merge($insertFields, $updateFields, $uniqueFields);
	}

	/**
	 * Replaces the value of the option while the stored one is still the value the caller has read.
	 *
	 * The write carries the value it expects to replace, so a caller that reads a record, makes up a new
	 * one and comes back to store it needs no lock: a record another request has written in between is
	 * left where it is, and the caller is told its own write did not happen.
	 *
	 * Storing the value a row already holds is not told from a lost race by every database - MySQL counts
	 * changed rows and PostgreSQL matched ones - so a caller that may repeat itself compares the two
	 * values before asking for the write.
	 *
	 * @param int $userId
	 * @param Option $option
	 * @param string $expectedValue
	 * @param string $value
	 * @return bool Whether the value has been replaced.
	 */
	public function replaceIfEquals(int $userId, Option $option, string $expectedValue, string $value): bool
	{
		$connection = Application::getConnection();

		$sql = new SqlExpression(
			'UPDATE ?# SET ?# = ?s WHERE ?# = ?i AND ?# = ?s AND ?# = ?s',
			OptionTable::getTableName(),
			'VALUE',
			$value,
			'USER_ID',
			$userId,
			'NAME',
			$option->value,
			'VALUE',
			$expectedValue,
		);

		$connection->queryExecute($sql->compile());

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Deletes the option while the stored value is still the value the caller has read.
	 *
	 * The counterpart of {@see replaceIfEquals()} for a caller that reads a record and comes back to drop
	 * it: a record another request has written in between stays where it is, and the caller is told its
	 * own delete did not happen. The answer of the database carries no ambiguity here - a row is either
	 * gone or untouched, and changed rows and matched ones are the same number - so unlike the replace
	 * this one needs no comparison before the write.
	 *
	 * @param int $userId
	 * @param Option $option
	 * @param string $expectedValue
	 * @return bool Whether the record has been deleted.
	 */
	public function deleteIfEquals(int $userId, Option $option, string $expectedValue): bool
	{
		$connection = Application::getConnection();

		$sql = new SqlExpression(
			'DELETE FROM ?# WHERE ?# = ?i AND ?# = ?s AND ?# = ?s',
			OptionTable::getTableName(),
			'USER_ID',
			$userId,
			'NAME',
			$option->value,
			'VALUE',
			$expectedValue,
		);

		$connection->queryExecute($sql->compile());

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Writes the option only while the person has none of it yet.
	 *
	 * The counterpart of {@see replaceIfEquals()} for a caller that has read no record at all: a record
	 * another request has written in between stays where it is - the pair of the person and the name is
	 * unique in the table - and the caller is told its own write did not happen.
	 *
	 * @param int $userId
	 * @param Option $option
	 * @param string $value
	 * @return bool Whether the record has been written.
	 */
	public function insertIfAbsent(int $userId, Option $option, string $value): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$fields = new SqlExpression(' (?#, ?#, ?#)', 'USER_ID', 'NAME', 'VALUE');
		$values = new SqlExpression(' VALUES (?i, ?s, ?s)', $userId, $option->value, $value);

		$connection->queryExecute(
			$helper->getInsertIgnore(
				$helper->quote(OptionTable::getTableName()),
				$fields->compile(),
				$values->compile(),
			)
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * @param int $userId
	 * @param Option $option
	 * @return void
	 * @throws ArgumentException
	 */
	public function delete(int $userId, Option $option): void
	{
		OptionTable::deleteByFilter([
			'USER_ID' => $userId,
			'NAME' => $option->value,
		]);
	}
}