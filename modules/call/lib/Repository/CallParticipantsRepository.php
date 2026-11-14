<?php

declare(strict_types=1);

namespace Bitrix\Call\Repository;

use Bitrix\Call\Model\CallUserTable;
use Bitrix\Call\Util;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;

/**
 * Who actually joined a call, as the journal row reads it.
 *
 * Read only: no writes, no events, no pushes. The memory lives as long as the instance, which is as long
 * as the service holding it, and is never dropped - membership of a finished call does not change under
 * the reader.
 *
 * Preloading is an optimisation and not a condition of correctness: a call nobody asked about in advance
 * is read on the spot. A method that answered with nothing until somebody preloaded it would one day
 * answer with nothing silently.
 *
 * @internal
 */
class CallParticipantsRepository
{
	/**
	 * How many participants of one call the page carries.
	 *
	 * Seven is what the row draws: the eighth face never reaches the markup, and the counter of the rest
	 * is built from the number of faces drawn. Hydrating the whole roster instead would cost the page a
	 * user load per participant of every conference on it, and a large room holds up to a thousand.
	 */
	public const PREVIEW_LIMIT = 7;

	/**
	 * How many participants of one call the participants layer carries.
	 *
	 * Twenty is the ceiling of the layer: past it the list ends with a tail naming how many people are
	 * left, and the number of the tail is built from the total the journal row already carries, not from
	 * anything read here. Without a ceiling one tap on a large room would read and hydrate a thousand
	 * people and answer with a couple of hundred kilobytes.
	 */
	public const ROSTER_LIMIT = 20;

	/**
	 * How large the roster of a call has to be before the call is read by a query of its own.
	 *
	 * A separate call to the database pays for itself over hundreds of rows saved, not over seven. A row of
	 * the roster is two integers, and carrying a hundred spare ones costs less than going to the database a
	 * second time - so a call joins the batch of the page until its roster runs past a hundred, spare rows
	 * and all. A page therefore costs two queries plus one for every call larger than that, which on an
	 * ordinary portal is two.
	 *
	 * Told apart from PREVIEW_LIMIT on purpose: that one answers how many faces the row draws, this one
	 * answers whether the call is worth a query of its own. They are not the same question, and the answer
	 * to the first is no answer to the second.
	 */
	public const SEPARATE_READ_THRESHOLD = 100;

	/**
	 * The preview of a call, by call id: at most PREVIEW_LIMIT participants in the order they joined.
	 *
	 * Also the marker of a call already read: a call present here has been asked about, even when its
	 * roster is empty.
	 *
	 * @var array<int, array<int, array{id: int, name: string, avatar: string, color: string}>>
	 */
	private array $previewByCall = [];

	/**
	 * How many participants joined the call, by call id. Counted over the whole roster rather than taken
	 * from the length of the preview.
	 *
	 * @var array<int, int>
	 */
	private array $totalByCall = [];

	private ?string $portalOrigin = null;

	/**
	 * Reads the preview and the size of the roster of several calls at once.
	 *
	 * Calls already in memory are skipped, so repeating the same page costs no second query.
	 *
	 * A call counts as read from the moment it is asked about: when the read fails, the exception goes on to
	 * the caller to be written down, and the calls it named stay with an empty roster and a total of nought
	 * for the life of the instance instead of being read again.
	 *
	 * @param array<int> $callIds
	 */
	public function preload(array $callIds): void
	{
		$unknown = [];
		foreach ($this->normalizeCallIds($callIds) as $callId)
		{
			if (!isset($this->previewByCall[$callId]))
			{
				$unknown[] = $callId;
			}
		}
		if ($unknown === [])
		{
			return;
		}

		// Marked as read and empty before the first query, and filled only once every read has succeeded: a
		// read that fails half way leaves its calls empty rather than unread. Unread, every row asking for one
		// of them next would go back to the database for what has just failed; half filled, a row would carry
		// a total counted before the failure next to a preview that never came.
		foreach ($unknown as $callId)
		{
			$this->previewByCall[$callId] = [];
			$this->totalByCall[$callId] = 0;
		}

		// The sizes come first and then decide how the preview is read: a call larger than
		// SEPARATE_READ_THRESHOLD is worth a query of its own, and nothing else knows which calls those are.
		$totals = array_fill_keys($unknown, 0);
		foreach ($this->loadTotals($unknown) as $callId => $total)
		{
			$totals[$callId] = $total;
		}

		$pickedByCall = $this->pickPreviewIds($totals);

		$previewUserIds = [];
		foreach ($pickedByCall as $userIds)
		{
			foreach ($userIds as $userId)
			{
				$previewUserIds[] = $userId;
			}
		}
		$this->warmUpUsers($previewUserIds);
		$users = Util::getUsers($previewUserIds);

		$previews = [];
		foreach ($pickedByCall as $callId => $userIds)
		{
			$previews[$callId] = [];
			foreach ($userIds as $userId)
			{
				// The directory drops a user whose account is gone, so the roster is shorter than the list
				// of ids the call was joined by - and the counter of the rest has to shrink with it.
				if (!isset($users[$userId]))
				{
					$totals[$callId]--;

					continue;
				}

				$previews[$callId][] = $this->toParticipant($users[$userId]);
			}
		}

		foreach ($previews as $callId => $preview)
		{
			$this->previewByCall[$callId] = $preview;
			$this->totalByCall[$callId] = $totals[$callId];
		}
	}

	/**
	 * The participants of one call, at most PREVIEW_LIMIT of them, in the order they joined.
	 *
	 * @return array<int, array{id: int, name: string, avatar: string, color: string}>
	 */
	public function getParticipants(int $callId): array
	{
		if ($callId <= 0)
		{
			return [];
		}

		if (!isset($this->previewByCall[$callId]))
		{
			$this->preload([$callId]);
		}

		return $this->previewByCall[$callId] ?? [];
	}

	/**
	 * How many participants joined the call, including the ones behind the preview.
	 */
	public function getTotal(int $callId): int
	{
		if ($callId <= 0)
		{
			return 0;
		}

		if (!isset($this->previewByCall[$callId]))
		{
			$this->preload([$callId]);
		}

		return $this->totalByCall[$callId] ?? 0;
	}

	/**
	 * The roster of one call as the participants layer reads it: the first ROSTER_LIMIT participants, in
	 * the order they joined.
	 *
	 * Read on its own and kept out of the memory of the page: what lies there is a preview of seven, and a
	 * whole cannot be rebuilt from a part. A participant whose account is gone from the directory falls out.
	 *
	 * @return array<int, array{id: int, name: string, avatar: string, color: string}>
	 */
	public function getAll(int $callId): array
	{
		if ($callId <= 0)
		{
			return [];
		}

		$userIds = $this->readRosterIds($callId, self::ROSTER_LIMIT);
		$this->warmUpUsers($userIds);
		$users = Util::getUsers($userIds);

		$participants = [];
		foreach ($userIds as $userId)
		{
			if (isset($users[$userId]))
			{
				$participants[] = $this->toParticipant($users[$userId]);
			}
		}

		return $participants;
	}

	/**
	 * Loads the people of the previews, or of the layer, into the user cache of im by one query.
	 *
	 * Util::getUsers() hydrates every user it does not hold yet through User::getInstance(), which on a cold
	 * cache is a query per user - up to PREVIEW_LIMIT of them per row of a group call and up to ROSTER_LIMIT
	 * for the layer. Warmed here, the same per-user cache answers each of them from memory; the users already
	 * in it cost no query at all.
	 *
	 * @param array<int> $userIds
	 */
	private function warmUpUsers(array $userIds): void
	{
		if ($userIds === [] || !Loader::includeModule('im'))
		{
			return;
		}

		UserCollection::initByUserIds($userIds);
	}

	/**
	 * @param array<int> $callIds
	 * @return array<int, int>
	 */
	private function normalizeCallIds(array $callIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $callIds),
			static fn (int $callId): bool => $callId > 0,
		)));
	}

	/**
	 * The first PREVIEW_LIMIT participants of every given call, by call id.
	 *
	 * The calls are split by the size of their roster, which loadTotals() has just measured. A call within
	 * SEPARATE_READ_THRESHOLD travels with the batch of the page, spare rows and all: a per-call limit in
	 * one statement needs a window function, so the batch brings the whole roster of every call in it and
	 * the cut is made here, in PHP. A call past the threshold is read on its own, with the limit set on the
	 * query - left in the batch, a single large room would make the page read a thousand rows to keep seven
	 * faces. Large calls are few, so this is a bounded number of queries of bounded length.
	 *
	 * A call nobody joined is read by neither: the count already says there is nothing behind it.
	 *
	 * @param array<int, int> $totalByCall Size of the roster of every call to read, by call id.
	 * @return array<int, array<int, int>>
	 */
	private function pickPreviewIds(array $totalByCall): array
	{
		$picked = array_fill_keys(array_keys($totalByCall), []);

		$batched = [];
		$batchedRows = 0;
		foreach ($totalByCall as $callId => $total)
		{
			if ($total <= 0)
			{
				continue;
			}

			if ($total > self::SEPARATE_READ_THRESHOLD)
			{
				$picked[$callId] = $this->readRosterIds($callId, self::PREVIEW_LIMIT);

				continue;
			}

			$batched[] = $callId;
			$batchedRows += $total;
		}

		if ($batched === [])
		{
			return $picked;
		}

		// The rows loadTotals() has just counted, and not one more: a guard against the two queries
		// disagreeing, not a limit of its own. Counted over the whole roster of every batched call rather
		// than over the previews of them - the order here runs by the time of joining across the whole batch,
		// so a limit the size of the previews would be spent on the calls that started first and would leave
		// the last of them without a roster at all.
		//
		// It bounds the read; it does not make the roster whole. FIRST_JOINED is a datetime of whole seconds
		// and USER_ID breaks the tie, so a row that arrives between the two queries within the same second
		// and with a smaller id stands ahead of rows another call of the batch still needed, and the limit
		// drops one of those instead. The price is taken knowingly: the race lasts the milliseconds between
		// the two queries, and costs one face of one row until the page is read again, never a row of data.
		$rows = CallUserTable::query()
			->setSelect(['CALL_ID', 'USER_ID'])
			->whereIn('CALL_ID', $batched)
			->whereNotNull('FIRST_JOINED')
			->setOrder(['FIRST_JOINED' => 'ASC', 'USER_ID' => 'ASC'])
			->setLimit($batchedRows)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$callId = (int)$row['CALL_ID'];
			// The count and the rows are two queries: somebody joining between them can make a batched call
			// one row longer than it was measured to be, and the cut has to hold all the same.
			if (count($picked[$callId]) >= self::PREVIEW_LIMIT)
			{
				continue;
			}

			$picked[$callId][] = (int)$row['USER_ID'];
		}

		return $picked;
	}

	/**
	 * Ids of the first $limit participants of one call, in the order they joined.
	 *
	 * @return array<int, int>
	 */
	private function readRosterIds(int $callId, int $limit): array
	{
		$rows = CallUserTable::query()
			->setSelect(['USER_ID'])
			->where('CALL_ID', $callId)
			->whereNotNull('FIRST_JOINED')
			->setOrder(['FIRST_JOINED' => 'ASC', 'USER_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['USER_ID'], $rows);
	}

	/**
	 * How many participants joined each of the given calls.
	 *
	 * Counted by the query rather than derived from the length of the preview, so that the number stays
	 * right whatever the preview is cut to.
	 *
	 * @param array<int> $callIds
	 * @return array<int, int>
	 */
	private function loadTotals(array $callIds): array
	{
		$rows = CallUserTable::query()
			->setSelect(['CALL_ID'])
			->addSelect(Query::expr()->count('USER_ID'), 'CNT')
			->whereIn('CALL_ID', $callIds)
			->whereNotNull('FIRST_JOINED')
			->setGroup(['CALL_ID'])
			->fetchAll()
		;

		$totals = [];
		foreach ($rows as $row)
		{
			$totals[(int)$row['CALL_ID']] = (int)$row['CNT'];
		}

		return $totals;
	}

	/**
	 * @param array<string, mixed> $user Entry of Util::getUsers().
	 * @return array{id: int, name: string, avatar: string, color: string}
	 */
	private function toParticipant(array $user): array
	{
		return [
			'id' => (int)$user['id'],
			'name' => (string)($user['name'] ?? ''),
			'avatar' => $this->toRootPath((string)($user['avatar'] ?? '')),
			'color' => (string)($user['color'] ?? ''),
		];
	}

	/**
	 * The picture of a user as a path from the root of the portal.
	 *
	 * The directory hands the address over absolute: Util::getUsers() reads the user with JSON formatting,
	 * and \Bitrix\Im\User::formatLegacyJson() prepends the public domain to every avatar that does not
	 * start with http. The client maps a picture by a path starting with a single slash and turns anything
	 * else into a silhouette without a word, so the origin is cut off here.
	 */
	private function toRootPath(string $avatar): string
	{
		if ($avatar === '')
		{
			return '';
		}

		$origin = $this->getPortalOrigin();
		if ($origin !== '' && str_starts_with($avatar, $origin))
		{
			return substr($avatar, strlen($origin));
		}

		return $avatar;
	}

	/**
	 * The origin the directory prepends, taken from the very source that prepends it.
	 *
	 * Deriving it from anywhere else - the request host, the url manager - would let the two disagree on
	 * the scheme or the server name, and a prefix that does not match is a prefix that is not cut off.
	 */
	private function getPortalOrigin(): string
	{
		if ($this->portalOrigin === null)
		{
			$this->portalOrigin = Loader::includeModule('im')
				? (string)\Bitrix\Im\Common::getPublicDomain()
				: '';
		}

		return $this->portalOrigin;
	}
}
