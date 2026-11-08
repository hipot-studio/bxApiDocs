<?php

namespace Bitrix\Main\Composite\Internals;

use Bitrix\Main\Composite\Helper;
use Bitrix\Main\Composite\Internals\Model\PageTable;
use Bitrix\Main\Type\DateTime;

class PageManager
{
	/**
	 * The seconds the first registration of a key waits for the one that is inserting the same key
	 */
	private const REGISTRATION_LOCK_TIMEOUT = 3;

	public static function register($cacheKey, array $params = [])
	{
		if (!is_string($cacheKey) || mb_strlen($cacheKey) < 1)
		{
			return null;
		}

		$options = Helper::getOptions();
		$writeStatistic = $options["WRITE_STATISTIC"] !== "N";
		$changed = isset($params["CHANGED"]) && $params["CHANGED"] === true;

		if (!$writeStatistic && !$changed)
		{
			/*
			 * Without statistics only a rewrite has anything to write, and a rewrite always arrives with
			 * CHANGED, so nothing follows this reading and it does not need the master
			 */
			$page = static::getByCacheKey($cacheKey);
			if ($page)
			{
				return (int)$page["ID"];
			}
		}

		$pageSize = isset($params["SIZE"]) ? (int)$params["SIZE"] : 0;
		$data = self::getRequestFields($params);

		$lockKey = null;
		$locked = false;
		$GLOBALS["DB"]->StartUsingMasterOnly();
		try
		{
			/*
			 * The read goes to the master too: its result is written back, and a lagging replica would
			 * hide a record just inserted and make the registration add a second one with the same key
			 */
			$page = static::getByCacheKey($cacheKey);

			if (!$page)
			{
				/*
				 * CACHE_KEY has no unique index to lean on, so the read and the insert of the first
				 * registration are serialized by a lock of the key: two parallel first renders would
				 * otherwise both find nothing and leave two records of one page behind
				 */
				$lockKey = "composite_page_register_" . $cacheKey;
				$locked = static::lockRegistration($lockKey);
				$page = static::getByCacheKey($cacheKey);
			}

			if ($page)
			{
				$pageId = (int)$page["ID"];
				$data += self::getCounterFields($page, $writeStatistic, $changed, $pageSize);

				/*
				 * An update returns early with no primary of its own: an empty set of fields, a failed
				 * validation of the required HOST or URI. Asking such a result for the id is a fatal error,
				 * and the id of the record is known here anyway
				 */
				$pageId = PageTable::update($pageId, $data)->isSuccess() ? $pageId : null;
			}
			else
			{
				$data["SIZE"] = $pageSize;
				$data["CACHE_KEY"] = $cacheKey;

				$result = PageTable::add($data);
				$pageId = $result->isSuccess() ? (int)$result->getId() : null;

				if ($pageId !== null && !$locked)
				{
					/*
					 * A registration the lock did not protect is still a registration, but the neighbour
					 * it raced may have inserted the same key meanwhile: the duplicate is collapsed here,
					 * within the request that left it, and the record that survived is the one returned
					 */
					$survivor = static::getByCacheKey($cacheKey);
					$pageId = $survivor ? (int)$survivor["ID"] : $pageId;
				}
			}
		}
		finally
		{
			if ($lockKey !== null)
			{
				Locker::unlock($lockKey);
			}

			// an exception from the update must not leave the rest of the request on the master
			$GLOBALS["DB"]->StopUsingMasterOnly();
		}

		return $pageId;
	}

	/**
	 * Takes the lock that serializes the first registration of the key
	 * @param string $lockKey
	 * @return bool true when the lock is granted by a database that really holds it
	 */
	protected static function lockRegistration(string $lockKey): bool
	{
		return Locker::lock($lockKey, self::REGISTRATION_LOCK_TIMEOUT) && Locker::isSupported();
	}

	/**
	 * Returns the fields the page takes from the request it was rendered by
	 * @param array $params
	 * @return array
	 */
	private static function getRequestFields(array $params): array
	{
		$pageTitle = $params["TITLE"] ?? $GLOBALS["APPLICATION"]->GetTitle();
		$pageHost = isset($params["HOST"]) && mb_strlen($params["HOST"]) ? $params["HOST"] : Helper::getHttpHost();
		$pageUri = isset($params["URI"]) && mb_strlen($params["URI"]) ? $params["URI"] : Helper::getRequestUri();

		return [
			"TITLE" => mb_substr($pageTitle, 0, 250),
			"URI" => mb_substr($pageUri, 0, 2000),
			"HOST" => mb_substr($pageHost, 0, 100),
		];
	}

	/**
	 * Returns the fields of the counters the registration moves: a view, a rewrite or both
	 * @param array $page the record of the page as it is now
	 * @param bool $writeStatistic
	 * @param bool $changed
	 * @param int $pageSize
	 * @return array
	 */
	private static function getCounterFields(array $page, bool $writeStatistic, bool $changed, int $pageSize): array
	{
		$fields = [];

		if ($writeStatistic)
		{
			$fields["LAST_VIEWED"] = new DateTime();
			$fields["VIEWS"] = $page["VIEWS"] + 1;
		}

		if ($changed)
		{
			$fields["CHANGED"] = new DateTime();
			$fields["REWRITES"] = $page["REWRITES"] + 1;
			$fields["SIZE"] = $pageSize;

			// without statistics only rewrites keep the LAST_VIEWED order of the cleanup meaningful
			$fields["LAST_VIEWED"] ??= new DateTime();
		}

		return $fields;
	}

	public static function getByCacheKey($cacheKey)
	{
		$records = PageTable::getList([
			// CACHE_KEY and URI are varchar(2000) each and the registration reads neither
			"select" => ["ID", "VIEWS", "REWRITES"],
			"filter" => [
				"=CACHE_KEY" => $cacheKey,
			],
			"order" => [
				"ID" => "ASC",
			],
		]);

		$result = null;
		$duplicateIds = [];
		while ($record = $records->fetch())
		{
			if ($result === null)
			{
				$result = $record;
			}
			else
			{
				$duplicateIds[] = $record["ID"];
			}
		}

		if (!empty($duplicateIds))
		{
			// delete duplicate records just in case
			$GLOBALS["DB"]->StartUsingMasterOnly();
			try
			{
				PageTable::deleteBatch(["ID" => $duplicateIds]);
			}
			finally
			{
				$GLOBALS["DB"]->StopUsingMasterOnly();
			}
		}

		return $result;
	}

	public static function deleteByCacheKey($cacheKey)
	{
		$records = PageTable::getList(array(
			"select" => array("ID"),
			"filter" => array(
				"=CACHE_KEY" => $cacheKey
			)
	  	));

		$result = null;
		while ($record = $records->fetch())
		{
			$result = PageTable::delete($record["ID"]);
		}

		return $result;
	}
}
