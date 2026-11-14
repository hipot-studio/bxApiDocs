<?php

namespace Bitrix\Disk\Internals\Index;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\Query\Join;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

abstract class BaseIndexTable extends DataManager
{
	private const REPORT_WINDOW_SECONDS = 60;

	/** @var array<string, int> unix time of the last record about a broken index table, by table name */
	private static array $reportedTables = [];

	public static function getMap()
	{
		return [
			new Fields\IntegerField("OBJECT_ID", [
				"required" => true,
				"primary" => true,
			]),
			new Fields\Relations\Reference(
				"OBJECT",
				ObjectTable::class,
				Join::on("this.OBJECT_ID", "ref.ID")
			),
			(new Fields\TextField("SEARCH_INDEX"))
				/** @see \Bitrix\Disk\Internals\Index\BaseIndexTable::limitValue */
				->addSaveDataModifier([static::class, 'limitValue']),
			new Fields\DatetimeField("UPDATE_TIME", [
				"required" => true,
				"default_value" => function(){
					return new DateTime();
				}
			]),
		];
	}

	/**
	 * @return int
	 */
	abstract public static function getMaxIndexSize();

	public static function limitValue($value)
	{
		//yes, we know that substr may kills some last characters
		return substr($value, 0, (int)static::getMaxIndexSize());
	}

	public static function upsert($objectId, $searchIndex)
	{
		$objectId = (int)$objectId;
		$searchIndex = static::normalizeSearchIndex($searchIndex);

		static::mergeOrSkip([
			'OBJECT_ID' => $objectId,
			'SEARCH_INDEX' => $searchIndex,
			'UPDATE_TIME'=>	new DateTime(),
		]);
	}

	/**
	 * The search index is recoverable, so a damaged index table must not cancel the operation that is
	 * already stored. Every other failure still reaches the caller.
	 */
	protected static function mergeOrSkip(array $data): void
	{
		try
		{
			static::merge($data);
		}
		catch (SqlQueryException $exception)
		{
			self::logSkippedWrite((int)$data['OBJECT_ID'], $exception);
		}
	}

	/**
	 * A reindexing walks every object of a broken table, so one record per table and window is enough to
	 * find it. The limit lives in the memory of the process: a new run of an agent starts with it empty and
	 * makes one record instead of one per object, and a long-lived process reports the table again once the
	 * window is over. A failure suppressed within the window costs no query and leaves the moment alone, so
	 * a table that fails without a pause is still reported once per window. The moment is stored before the
	 * journal is asked: a journal that fails is not asked again for every object of the same table.
	 */
	private static function logSkippedWrite(int $objectId, SqlQueryException $exception): void
	{
		$table = static::getTableName();
		$now = time();
		if (self::isReportWindowOpen(self::$reportedTables[$table] ?? null, $now))
		{
			return;
		}

		self::$reportedTables[$table] = $now;

		try
		{
			// The exception itself stays out of the record: LogFormatter would append its query, and the
			// query carries SEARCH_INDEX, that is the indexed content of the object.
			ServiceLocator::getInstance()->get('disk.searchIndexingLogger')->error(
				'Search index write skipped: table={table}, objectId={objectId}, '
				. 'exception={exception}, message={message}',
				[
					'table' => $table,
					'objectId' => $objectId,
					'exception' => $exception::class,
					'message' => self::cutAtFirstQuotedValue($exception->getMessage()),
				],
			);
		}
		catch (\Throwable)
		{
			// The operation is already stored, so a diagnostic of its own failure must not cancel it.
		}
	}

	/**
	 * A moment in the future, left by a clock that went back, opens no window.
	 */
	private static function isReportWindowOpen(?int $moment, int $now): bool
	{
		if ($moment === null)
		{
			return false;
		}

		$elapsed = $now - $moment;

		return $elapsed >= 0 && $elapsed < self::REPORT_WINDOW_SECONDS;
	}

	/**
	 * Everything from the quote the database opens can carry a fragment of the indexed content, and
	 * where that value ends is not decidable, so the message is kept only up to that quote. A quote is
	 * opened by a character with no letter, digit or underscore before it, which leaves the apostrophe
	 * of a word such as "Can't" alone, so the code and the reason of the error survive.
	 */
	private static function cutAtFirstQuotedValue(string $message): string
	{
		$length = strlen($message);
		for ($position = 0; $position < $length; $position++)
		{
			$character = $message[$position];
			if ($character !== "'" && $character !== '"')
			{
				continue;
			}

			$previous = $position > 0 ? $message[$position - 1] : '';
			if (self::isWordCharacter($previous))
			{
				continue;
			}

			return substr($message, 0, $position) . $character . '?' . $character;
		}

		return $message;
	}

	private static function isWordCharacter(string $character): bool
	{
		$code = $character === '' ? 0 : ord($character);

		// 0-9, A-Z, a-z and the underscore by code: the ctype extension is optional on a portal.
		return ($code >= 48 && $code <= 57)
			|| ($code >= 65 && $code <= 90)
			|| ($code >= 97 && $code <= 122)
			|| $code === 95
		;
	}

	protected static function normalizeSearchIndex($searchIndex)
	{
		return static::limitValue(trim($searchIndex));
	}

	protected static function updateByFilterAndGetAffectedRows(array $fields, array $filter): int
	{
		parent::updateBatch($fields, $filter);

		return Application::getConnection()->getAffectedRowsCount();
	}

	protected static function getPrimaryFieldsForMerge()
	{
		return ['OBJECT_ID'];
	}
}
