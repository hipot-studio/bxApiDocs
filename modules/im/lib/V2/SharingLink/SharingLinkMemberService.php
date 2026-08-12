<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

use Bitrix\Im\Model\SharingLinkMemberTable;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

/**
 * Guest→link bindings: who joined via which link and whether a guest still holds valid access.
 */
class SharingLinkMemberService
{
	private const QUERY_CHUNK_SIZE = 300;

	private static ?self $instance = null;

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	private function __construct()
	{
	}

	/**
	 * Idempotent (UNIQUE link+user): a returning guest is a no-op. Entity pair mirrors the link's.
	 */
	public function track(int $linkId, int $userId, string $entityType, string $entityId): void
	{
		if ($linkId <= 0 || $userId <= 0 || $entityType === '' || $entityId === '')
		{
			return;
		}

		$connection = Application::getInstance()->getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = SharingLinkMemberTable::getTableName();

		$prepared = $sqlHelper->prepareInsert($tableName, [
			'SHARING_LINK_ID' => $linkId,
			'USER_ID' => $userId,
			'ENTITY_TYPE' => $entityType,
			'ENTITY_ID' => $entityId,
			'DATE_CREATE' => new DateTime(),
		]);

		$connection->queryExecute(
			$sqlHelper->getInsertIgnore(
				$sqlHelper->quote($tableName),
				" ({$prepared[0]}) ",
				" VALUES ({$prepared[1]})"
			)
		);
	}

	/**
	 * @param list<int> $linkIds
	 * @return list<int> distinct user ids that joined through the links
	 */
	public function getUserIdsByLinkIds(array $linkIds): array
	{
		$linkIds = $this->normalizeIds($linkIds);
		if ($linkIds === [])
		{
			return [];
		}

		$userIds = [];
		foreach (array_chunk($linkIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$result = SharingLinkMemberTable::query()
				->setSelect(['USER_ID'])
				->whereIn('SHARING_LINK_ID', $chunk)
				->exec()
			;
			while ($row = $result->fetch())
			{
				$userId = (int)$row['USER_ID'];
				$userIds[$userId] = $userId;
			}
		}

		return array_values($userIds);
	}

	/**
	 * Users still holding another live link to this chat — they keep their seat on revocation.
	 *
	 * @param list<int> $userIds
	 * @param list<int> $excludedLinkIds
	 * @return array<int, true> user ids as a lookup set
	 */
	public function getUsersWithAnotherActiveLinkInChat(array $userIds, int $chatId, array $excludedLinkIds): array
	{
		$userIds = $this->normalizeIds($userIds);
		if ($userIds === [] || $chatId <= 0)
		{
			return [];
		}

		$excludedLinkIds = $this->normalizeIds($excludedLinkIds);

		$usersToKeep = [];
		foreach (array_chunk($userIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$query = SharingLinkMemberTable::query()
				->setSelect(['USER_ID'])
				->whereIn('USER_ID', $chunk)
				->where('ENTITY_ID', (string)$chatId)
				->whereIn('ENTITY_TYPE', LinkEntityType::chatTypeValues())
				->where('BLOCKED', 'N')
				->where('SHARING_LINK.IS_REVOKED', 'N')
			;
			if ($excludedLinkIds !== [])
			{
				$query->whereNotIn('SHARING_LINK_ID', $excludedLinkIds);
			}

			$result = $query->exec();
			while ($row = $result->fetch())
			{
				$usersToKeep[(int)$row['USER_ID']] = true;
			}
		}

		return $usersToKeep;
	}

	/**
	 * Any non-revoked link in any chat — conservative keep-alive criterion for the guest session.
	 */
	public function hasAnyActiveLink(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return SharingLinkMemberTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('BLOCKED', 'N')
			->where('SHARING_LINK.IS_REVOKED', 'N')
			->setLimit(1)
			->fetch() !== false
		;
	}

	/**
	 * Live-link candidates for the session-validity check; chatId lets the caller batch membership.
	 *
	 * @return list<array{code: string, chatId: int}>
	 */
	public function getActiveLinkCandidatesByUser(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$rows = SharingLinkMemberTable::query()
			->setSelect(['ENTITY_ID', 'CODE' => 'SHARING_LINK.CODE'])
			->where('USER_ID', $userId)
			->whereIn('ENTITY_TYPE', LinkEntityType::chatTypeValues())
			->where('BLOCKED', 'N')
			->where('SHARING_LINK.IS_REVOKED', 'N')
			->fetchAll()
		;

		$candidates = [];
		foreach ($rows as $row)
		{
			$code = (string)($row['CODE'] ?? '');
			// ENTITY_TYPE is scoped to chat types above, so ENTITY_ID is a chat id here.
			$chatId = (int)($row['ENTITY_ID'] ?? 0);
			if ($code !== '' && $chatId > 0)
			{
				$candidates[$code] = ['code' => $code, 'chatId' => $chatId];
			}
		}

		return array_values($candidates);
	}

	/**
	 * @param list<int> $linkIds
	 */
	public function deleteByLinkIds(array $linkIds): void
	{
		$linkIds = $this->normalizeIds($linkIds);
		if ($linkIds === [])
		{
			return;
		}

		foreach (array_chunk($linkIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			SharingLinkMemberTable::deleteByFilter(['@SHARING_LINK_ID' => $chunk]);
		}
	}

	/**
	 * Keeps BLOCKED tombstones — they must survive the kick to bar re-entry.
	 */
	public function deleteByChatAndUser(int $chatId, int $userId): void
	{
		if ($chatId <= 0 || $userId <= 0)
		{
			return;
		}

		SharingLinkMemberTable::deleteByFilter([
			'@ENTITY_TYPE' => LinkEntityType::chatTypeValues(),
			'=ENTITY_ID' => (string)$chatId,
			'=USER_ID' => $userId,
			'=BLOCKED' => 'N',
		]);
	}

	/**
	 * Blocks the guest on the kicker's own links; a regenerated link (new id) is the natural un-block.
	 */
	public function blockGuestForChatLinksOfAuthor(int $guestUserId, int $chatId, int $authorId): void
	{
		if ($guestUserId <= 0 || $chatId <= 0 || $authorId <= 0)
		{
			return;
		}

		$rows = SharingLinkMemberTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $guestUserId)
			->where('ENTITY_ID', (string)$chatId)
			->whereIn('ENTITY_TYPE', LinkEntityType::chatTypeValues())
			->where('SHARING_LINK.AUTHOR_ID', $authorId)
			->fetchAll()
		;

		$ids = [];
		foreach ($rows as $row)
		{
			$ids[] = (int)$row['ID'];
		}
		if ($ids === [])
		{
			return;
		}

		SharingLinkMemberTable::updateMulti($ids, ['BLOCKED' => 'Y'], true);
	}

	/**
	 * Was the guest blocked on this link (kicked by its author) — re-join must be denied.
	 */
	public function isBlockedForLink(int $linkId, int $userId): bool
	{
		if ($linkId <= 0 || $userId <= 0)
		{
			return false;
		}

		return SharingLinkMemberTable::query()
			->setSelect(['ID'])
			->where('SHARING_LINK_ID', $linkId)
			->where('USER_ID', $userId)
			->where('BLOCKED', 'Y')
			->setLimit(1)
			->fetch() !== false
		;
	}

	public function deleteByUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		SharingLinkMemberTable::deleteByFilter(['=USER_ID' => $userId]);
	}

	/**
	 * @param list<int> $ids
	 * @return list<int> positive, de-duplicated
	 */
	private function normalizeIds(array $ids): array
	{
		$normalized = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalized[$id] = $id;
			}
		}

		return array_values($normalized);
	}
}
