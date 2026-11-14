<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\File;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Security\Random;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\UserProvider;
use Bitrix\UI\EntitySelector\Item;

/**
 * The people a board offers for a mention before anything is typed: the users of its active
 * sessions, newest opening first, as far as the selector of the portal lets the asking user see them.
 *
 * Pages of raw user ids share the board's snapshot and expiry, the asking user among them. Who may
 * be shown is decided on every read by the selector provider; neither rights nor profiles are cached.
 */
final class MentionCandidateProvider
{
	public const LIMIT = 20;

	private const CACHE_TTL = 15;
	private const SESSION_PAGE_SIZE = 50;
	private const CACHE_DIR = '/disk/flipchart/mention_candidates/';
	private const CACHE_ID_PREFIX = 'board_v2_';

	/**
	 * Returns the selector items of the users of the active sessions of the board, in the order of
	 * the sessions, without the asking user, at most {@see self::LIMIT} of them.
	 *
	 * @param File $board
	 * @param int $currentUserId
	 * @return list<Item>
	 */
	public function getCandidateItems(File $board, int $currentUserId): array
	{
		$objectId = (int)$board->getRealObjectId();
		$page = $this->getSessionPage($objectId);
		if ($page['sessions'] === [] || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$provider = new UserProvider();
		if (!$provider->isAvailable())
		{
			return [];
		}

		$candidates = [];
		do
		{
			$userIds = array_map('intval', array_column($page['sessions'], 'USER_ID'));
			foreach (array_chunk($userIds, self::LIMIT) as $portion)
			{
				$portion = array_values(array_filter(
					$portion,
					static fn(int $userId): bool => $userId !== $currentUserId && !isset($candidates[$userId]),
				));
				if ($portion === [])
				{
					continue;
				}
				$items = $provider->getUserItems([
					'userId' => $portion,
					'!userId' => [$currentUserId],
					'limit' => count($portion),
				]);
				foreach ($this->orderBySessions($items, $portion) as $item)
				{
					$candidates[(int)$item->getId()] = $item;
					if (count($candidates) === self::LIMIT)
					{
						return array_values($candidates);
					}
				}
			}
			if (count($page['sessions']) < self::SESSION_PAGE_SIZE)
			{
				break;
			}
			$cursor = (int)$page['sessions'][self::SESSION_PAGE_SIZE - 1]['NEWEST_ID'];
			$page = $this->getSessionPage($objectId, $page['snapshot'], $cursor);
		}
		while ($page['sessions'] !== []);

		return array_values($candidates);
	}

	/**
	 * @param Item[] $items
	 * @param list<int> $userIds
	 * @return list<Item>
	 */
	private function orderBySessions(array $items, array $userIds): array
	{
		$itemsByUserId = [];
		foreach ($items as $item)
		{
			$itemsByUserId[(int)$item->getId()] = $item;
		}

		$ordered = [];
		foreach ($userIds as $userId)
		{
			if (!isset($itemsByUserId[$userId]))
			{
				continue;
			}

			$ordered[] = $itemsByUserId[$userId];
			if (count($ordered) === self::LIMIT)
			{
				break;
			}
		}

		return $ordered;
	}

	/**
	 * Each cursor names an immutable page of a generation, so competing readers cannot lose an
	 * appended portion or mix pages from different snapshots. Only the root key is board-stable.
	 */
	private function getSessionPage(int $objectId, ?array $snapshot = null, ?int $cursor = null): array
	{
		$cacheId = self::CACHE_ID_PREFIX . $objectId;
		$cacheDir = self::CACHE_DIR . substr(md5($cacheId), 2, 2) . '/' . $objectId . '/';
		$expiresAt = $snapshot['expiresAt'] ?? time() + self::CACHE_TTL;
		if ($snapshot !== null)
		{
			$cacheId .= '_' . $snapshot['generation'] . '_' . $cursor;
		}

		$cache = Cache::createInstance();
		if ($cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			$page = $cache->getVars();
			if ($this->isFreshPage($page))
			{
				return $page;
			}
			$cache->forceRewriting(true);
		}

		$ttl = $expiresAt - time();
		$writing = $ttl > 0 && $cache->startDataCache($ttl, $cacheId, $cacheDir);
		if (!$writing)
		{
			$page = $cache->getVars();
			if ($this->isFreshPage($page))
			{
				return $page;
			}
		}

		try
		{
			$sessions = $this->readSessionPage($objectId, $snapshot['anchor'] ?? null, $cursor);
			$snapshot ??= [
				'anchor' => (int)($sessions[0]['NEWEST_ID'] ?? 0),
				'generation' => Random::getString(16, true),
				'expiresAt' => $expiresAt,
			];
			$page = [
				'snapshot' => $snapshot,
				'sessions' => $sessions,
			];
			if ($writing)
			{
				if ($expiresAt > time())
				{
					$cache->endDataCache($page);
				}
				else
				{
					$cache->abortDataCache();
				}
			}
		}
		catch (\Throwable $exception)
		{
			$cache->abortDataCache();

			throw $exception;
		}

		return $page;
	}

	private function isFreshPage(mixed $page): bool
	{
		return is_array($page)
			&& is_array($page['sessions'] ?? null)
			&& is_array($page['snapshot'] ?? null)
			&& is_int($page['snapshot']['anchor'] ?? null)
			&& is_string($page['snapshot']['generation'] ?? null)
			&& is_int($page['snapshot']['expiresAt'] ?? null)
			&& $page['snapshot']['expiresAt'] > time()
		;
	}

	/** @return list<array{USER_ID: int, NEWEST_ID: int|string}> */
	private function readSessionPage(int $objectId, ?int $anchor, ?int $cursor): array
	{
		$query = DocumentSessionTable::query()
			->registerRuntimeField(new ExpressionField('NEWEST_ID', 'MAX(%s)', ['ID']))
			->setSelect(['USER_ID', 'NEWEST_ID'])
			->where('OBJECT_ID', $objectId)
			->where('SERVICE', DocumentService::FlipChart->value)
			->where('STATUS', DocumentSession::STATUS_ACTIVE)
			->whereNull('VERSION_ID')
			->where('IS_EXCLUSIVE', false)
			->where('USER_ID', '>', 0)
			->setGroup(['USER_ID'])
			->setOrder(['NEWEST_ID' => 'DESC'])
			->setLimit(self::SESSION_PAGE_SIZE)
		;
		if ($anchor !== null)
		{
			$query->where('ID', '<=', $anchor);
		}
		if ($cursor !== null)
		{
			$query->having('NEWEST_ID', '<', $cursor);
		}

		return $query->exec()->fetchAll();
	}
}
