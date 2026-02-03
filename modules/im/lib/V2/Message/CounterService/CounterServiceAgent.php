<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\CounterService;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Message\CounterService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use CAgent;

final class CounterServiceAgent
{
	private const UNREAD_DELETE_ALL_LIMIT = 100000;
	private const UNREAD_DELETE_ALL_INTERVAL = 10;
	private const CLEANUP_GHOST_COUNTERS_LIMIT = 100;

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private static function getLastUnreadId(int $userId, bool $withNotify): int
	{
		$result = MessageUnreadTable::getList([
			'select' => ['ID'],
			'filter' => self::buildDeleteAllFilter($userId, $withNotify),
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		]);

		if (is_array($row = $result->fetch()))
		{
			return (int)$row['ID'];
		}

		return 0;
	}

	private static function buildDeleteAllFilter(int $userId, bool $withNotify): array
	{
		$filter = ['=USER_ID' => $userId];

		if (!$withNotify)
		{
			$filter['!=CHAT_TYPE'] = \IM_MESSAGE_SYSTEM;
		}

		return $filter;
	}

	private static function formatDeleteAllAgentName(int $userId, bool $withNotify, int $lastUnreadId): string
	{
		$params = [
			$userId,
			$withNotify ? 'true' : 'false',
			$lastUnreadId,
		];

		return __CLASS__ . '::deleteAll(' . implode(', ', $params) . ');';
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public static function deleteAllViaAgent(int $userId, bool $withNotify): void
	{
		$lastUnreadId = self::getLastUnreadId($userId, $withNotify);
		$agentName = self::deleteAll($userId,$withNotify, $lastUnreadId);

		if ($agentName !== '')
		{
			CAgent::addAgent(
				$agentName,
				'im',
				'N',
				self::UNREAD_DELETE_ALL_INTERVAL,
			);
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public static function deleteAll(int $userId, bool $withNotify, int $lastUnreadId): string
	{
		$filter = self::buildDeleteAllFilter($userId, $withNotify);
		$limit = (int)Option::get('im', 'unread_delete_all_limit', self::UNREAD_DELETE_ALL_LIMIT);
		$result = MessageUnreadTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'limit' => $limit,
		]);

		$ids = [];

		foreach ($r = $result->fetchAll() as $row)
		{
			$ids[] = (int)$row['ID'];
		}

		if (empty($ids))
		{
			return '';
		}

		MessageUnreadTable::deleteByFilter(['@ID' => $ids]);
		CounterService::clearCache($userId);
		CounterOverflowService::deleteAllByUserId($userId);

		if (count($ids) < $limit)
		{
			return '';
		}

		return self::formatDeleteAllAgentName($userId, $withNotify, $lastUnreadId);
	}

	public static function cleanGhostCountersAgent(): string
	{
		return '';

		//TODO: Optimize this query
		$query = MessageUnreadTable::query()
			->setSelect(['ID', 'USER_ID', 'CHAT_ID'])
			->registerRuntimeField(
				new Reference(
					'RELATION',
					RelationTable::class,
					Join::on('this.CHAT_ID', 'ref.CHAT_ID')
						->whereColumn('this.USER_ID', 'ref.USER_ID'),
					['join_type' => Join::TYPE_LEFT]
				)
			)
			->whereNot('CHAT_TYPE', \IM_MESSAGE_SYSTEM)
			->whereNull('RELATION.ID')
			->setLimit(self::CLEANUP_GHOST_COUNTERS_LIMIT)
		;

		/** @var array<int, array{ID:mixed, USER_ID:mixed, CHAT_ID:mixed}> $results */
		$results = $query->fetchAll();

		if (empty($results))
		{
			return __METHOD__ . '();';
		}

		$idsToDelete = [];
		$overflowToCleanMap = [];
		$usersToClearCache = [];

		foreach ($results as $row)
		{
			$idsToDelete[] = (int)$row['ID'];
			$userId = (int)$row['USER_ID'];
			$chatId = (int)$row['CHAT_ID'];

			$overflowToCleanMap[$userId][] = $chatId;
			$usersToClearCache[$userId] = $userId;
		}

		MessageUnreadTable::deleteByFilter(['@ID' => $idsToDelete]);
		CounterOverflowService::deleteBatch($overflowToCleanMap);

		foreach ($usersToClearCache as $userId)
		{
			CounterService::clearCache($userId);
		}

		return __METHOD__ . '();';
	}
}
