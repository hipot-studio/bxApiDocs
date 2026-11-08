<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\TrackedObjectTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserAccessTable;

/**
 * Who a record about an object is addressed to.
 *
 * An object is visible through two independent sources, and the audience of a record is their sum:
 * the rights of the module, which are already written down as the access codes of the object, and the
 * interaction set, which carries an object to a user the rights say nothing about. The second source
 * has no code of the portal behind it, so it is given one here - a personal code, addressed to a
 * single user and to nobody else.
 *
 * A personal code is handed only to a user the rights do not already reach. The rule is what keeps
 * the table of codes from doubling: were the code added to everyone, every record of every object
 * would carry one row per user who ever opened it, on top of the rows the rights already cover, and
 * the second set would address nobody the first did not.
 *
 * Two codes of the module are not codes of a user at all and are dealt with here rather than left to
 * the reader. CR stands for the creator of the object it is written on, so it means nothing outside
 * that object and is replaced with the personal user code of the creator. AU stands for any
 * authorised user and is kept as it is: it cannot be unfolded into the users of a portal, so the
 * reader of the journal counts it among the codes of every authorised user.
 */
final class AudienceResolver
{
	/**
	 * Personal codes share the column with the access codes of the portal, and the reader intersects
	 * that column with the codes a user actually holds, so a personal code that a provider of the
	 * portal could also produce would hand its owner the records of a stranger.
	 *
	 * The dot is what makes that impossible rather than unlikely: every provider writing b_user_access
	 * builds a code out of capital letters and the identifier of a row - U, IU, G, SG, DR, D and the
	 * like - and none of them is written with a separator inside.
	 */
	private const PERSONAL_CODE_PREFIX = 'DISK.SYNC.U';

	/**
	 * How many identifiers one statement carries when the users of an interaction set are classified.
	 */
	private const IDS_PER_QUERY = 500;

	public static function personalCodeOf(int $userId): string
	{
		return self::PERSONAL_CODE_PREFIX . $userId;
	}

	/**
	 * The codes a record about the object is published to: the access codes of the object plus the
	 * personal codes of the users who hold it by interaction alone.
	 *
	 * @return string[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function forRealObject(int $realObjectId): array
	{
		$rightsCodes = $this->rightsCodesOf($realObjectId);

		return array_values(array_unique(array_merge(
			$rightsCodes,
			self::personalCodesOf($this->interactionOnlyUserIds($realObjectId, $rightsCodes)),
		)));
	}

	/**
	 * The second half of that audience on its own: the personal codes of the users the interaction set
	 * carries the object to and the rights of the module do not reach.
	 *
	 * It is the half a caller addressing the holders alone needs, and it is taken from here rather
	 * than as the difference of {@see forRealObject()} and {@see rightsCodesOf()}, which is the same
	 * set arrived at by reading the codes of the object twice. Reading them once is not a saved
	 * statement but a saved walk: the codes come from the nearest checkpoint above the object, found
	 * over the closure of the tree.
	 *
	 * @return string[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function personalCodesOfInteractionOnlyHolders(int $realObjectId): array
	{
		return self::personalCodesOf($this->idsOfInteractionOnlyHolders($realObjectId));
	}

	/**
	 * The same half of the audience, named by the identifiers of its holders.
	 *
	 * A caller writing a record per addressee needs it in that shape. The personal codes above name
	 * exactly these users, but a user is not something a code can be read back out of - the shape of
	 * one belongs here and nowhere else - and the codes are of no use where the addressee is an
	 * identifier.
	 *
	 * Handing the identifiers over from here is also what keeps the interaction set read once for such
	 * a caller: taken beside the codes, the set would be gone over a second time to put a name to every
	 * holder the first pass had already found.
	 *
	 * The set is read first and the codes of the object only after it, because the caller is the
	 * cascade of a deletion and arrives here once per file: a file no set carries - most of them on a
	 * portal - is answered by that one statement, where reading the codes would walk the closure of the
	 * tree to the nearest checkpoint for nothing. The short circuit on AU loses nothing by standing
	 * below that read: it only ever decides something when the set has rows, and an empty set has
	 * nobody to publish to either way.
	 *
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function idsOfInteractionOnlyHolders(int $realObjectId): array
	{
		$userIds = $this->interactionUserIds($realObjectId);
		if ($userIds === [])
		{
			return [];
		}

		return $this->interactionOnlyAmong($userIds, $this->rightsCodesOf($realObjectId));
	}

	/**
	 * The access codes of the object, as the rights of the module hold them.
	 *
	 * @return string[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function rightsCodesOf(int $realObjectId): array
	{
		if ($realObjectId <= 0)
		{
			return [];
		}

		$codes = Driver::getInstance()->getRightsManager()->getSimpleRightAccessCodesForObject($realObjectId);

		return $this->withCreatorUnfolded($codes, $realObjectId);
	}

	/**
	 * Whether the user sees the object by the rights of the module - the question that decides whether
	 * an interaction of that user is a source of visibility of its own.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function hasRightsAccess(int $realObjectId, int $userId): bool
	{
		$codes = $this->rightsCodesOf($realObjectId);
		if ($codes === [])
		{
			return false;
		}

		if (in_array(AccessCodeEnum::AUTHORIZED_USER->value, $codes, true))
		{
			return true;
		}

		return isset($this->usersHoldingAnyOf([$userId], $codes)[$userId]);
	}

	/**
	 * @param string[] $codes
	 * @return string[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function withCreatorUnfolded(array $codes, int $realObjectId): array
	{
		$creatorPosition = array_search(AccessCodeEnum::CREATOR->value, $codes, true);
		if ($creatorPosition === false)
		{
			return $codes;
		}

		unset($codes[$creatorPosition]);

		$createdBy = $this->createdBy($realObjectId);
		if ($createdBy !== null)
		{
			$codes[] = AccessCodeEnum::USER->value . $createdBy;
		}

		return array_values($codes);
	}

	/**
	 * The half for a caller holding the codes of the object and not the set. Having them in hand is
	 * what lets the circuit on AU stand above the read of the set here: an object every authorised user
	 * sees hands out no personal code at all, so the set is left unread rather than read and dropped.
	 *
	 * @param string[] $rightsCodes
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function interactionOnlyUserIds(int $realObjectId, array $rightsCodes): array
	{
		if (in_array(AccessCodeEnum::AUTHORIZED_USER->value, $rightsCodes, true))
		{
			return [];
		}

		return $this->interactionOnlyAmong($this->interactionUserIds($realObjectId), $rightsCodes);
	}

	/**
	 * The one place the half is worked out, from a set and codes both already read: the users of the
	 * interaction set the given codes of the object do not reach. A holder named by an identifier here
	 * and by a personal code above is the same holder, because the code is made out of the identifier
	 * and never arrived at separately.
	 *
	 * @param int[] $userIds
	 * @param string[] $rightsCodes
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function interactionOnlyAmong(array $userIds, array $rightsCodes): array
	{
		if (in_array(AccessCodeEnum::AUTHORIZED_USER->value, $rightsCodes, true))
		{
			// Everyone authorised already sees the object, so no interaction is a source of its own
			// and the whole set would be handed a personal code that addresses nothing new.
			return [];
		}

		$reachedByRights = $rightsCodes === [] ? [] : $this->usersHoldingAnyOf($userIds, $rightsCodes);

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => !isset($reachedByRights[$userId]),
		));
	}

	/**
	 * @param int[] $userIds
	 * @return string[]
	 */
	private static function personalCodesOf(array $userIds): array
	{
		return array_map(static fn (int $userId): string => self::personalCodeOf($userId), $userIds);
	}

	/**
	 * The users holding the object in their interaction set, read by the identifier of the real object
	 * the set keeps as a field of its own.
	 *
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function interactionUserIds(int $realObjectId): array
	{
		$rows = TrackedObjectTable::query()
			->setSelect(['USER_ID'])
			->where('REAL_OBJECT_ID', $realObjectId)
			->setDistinct()
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['USER_ID'], $rows);
	}

	/**
	 * @param int[] $userIds
	 * @param string[] $codes
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function usersHoldingAnyOf(array $userIds, array $codes): array
	{
		$holding = [];
		foreach (array_chunk($userIds, self::IDS_PER_QUERY) as $chunk)
		{
			$rows = UserAccessTable::query()
				->setSelect(['USER_ID'])
				->whereIn('USER_ID', $chunk)
				->whereIn('ACCESS_CODE', $codes)
				->setDistinct()
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$holding[(int)$row['USER_ID']] = true;
			}
		}

		return $holding;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function createdBy(int $realObjectId): ?int
	{
		$row = ObjectTable::getRow([
			'select' => ['CREATED_BY'],
			'filter' => ['=ID' => $realObjectId],
		]);

		return $row === null ? null : (int)$row['CREATED_BY'];
	}
}
