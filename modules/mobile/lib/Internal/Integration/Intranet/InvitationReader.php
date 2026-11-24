<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Intranet;

use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Intranet\Internals\InvitationTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Integration\PerUserRights;
use Bitrix\Mobile\Internal\Integration\UnreadableRightsException;

/**
 * Addressed invitations of a pack of users and their right to invite.
 *
 * An addressed invitation is a record naming the one invited and the one who invited him. Joining by the
 * invitation link of the portal leaves no such record, so sharing the link is not an addressed invitation.
 * The records are read in portions of CHUNK_SIZE, one grouped query per portion.
 *
 * The right is a separate answer, asked of the structure per user. Null means a module it needs is
 * missing; a read that fails throws what it met, since a failure is not an unknown - a right fails only
 * the users it could not be read for.
 */
class InvitationReader
{
	public const CHUNK_SIZE = 500;

	/**
	 * @param int[] $userIds
	 * @return int[]|null the users of the pack who invited somebody at or after the moment, to the second
	 */
	public function getAddressedSendersSince(array $userIds, DateTime $since): ?array
	{
		if (!$this->isModuleAvailable('intranet'))
		{
			return null;
		}

		$senderIds = [];
		foreach (array_chunk(self::normalize($userIds), self::CHUNK_SIZE) as $chunk)
		{
			foreach ($this->createSendersQuery($chunk, $since)->fetchAll() as $row)
			{
				$senderIds[] = (int)$row['ORIGINATOR_ID'];
			}
		}

		return $senderIds;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool>|null userId => the right to invite
	 * @throws UnreadableRightsException naming the users whose right could not be read, with the rights
	 *     of the others
	 */
	public function canInvite(array $userIds): ?array
	{
		if (!$this->isModuleAvailable('intranet') || !$this->isModuleAvailable('humanresources'))
		{
			return null;
		}

		return PerUserRights::read(
			self::normalize($userIds),
			fn(int $userId): bool => $this->readInvitePermission($userId),
		);
	}

	/**
	 * No index of b_intranet_invitation starts with the originator, so one query per user would read the
	 * date range again for each of them; grouped, the range is read once per portion.
	 *
	 * @param int[] $userIds a non-empty portion
	 */
	protected function createSendersQuery(array $userIds, DateTime $since): Query
	{
		return InvitationTable::query()
			->setSelect(['ORIGINATOR_ID'])
			->whereIn('ORIGINATOR_ID', $userIds)
			->where('DATE_CREATE', '>=', $since)
			->setGroup(['ORIGINATOR_ID'])
		;
	}

	/**
	 * The rule the menu of the application shows the invite button by, built by user id the way intranet
	 * builds it for a user who is not the current one.
	 */
	protected function readInvitePermission(int $userId): bool
	{
		return (new PermissionInvitation($userId))->canInvite();
	}

	protected function isModuleAvailable(string $moduleId): bool
	{
		return Loader::includeModule($moduleId);
	}

	/**
	 * @param array<int|string> $userIds
	 * @return int[]
	 */
	private static function normalize(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
	}
}
