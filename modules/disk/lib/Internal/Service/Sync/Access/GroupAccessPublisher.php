<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Access;

use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\AudienceResolver;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeRecord;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\UserToGroupTable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A move of the boundary of a workgroup, told to the journal.
 *
 * Membership goes past the rights of Disk entirely. The storage of a group carries the codes SG{id}_*
 * and never the users behind them, so joining or leaving writes nothing at all into the tables of the
 * module: what changed is the set of codes of one user, held by the portal. The handlers of
 * socialnetwork are the only place the change is visible from, and this class is what they hand it to.
 *
 * The addressing of these records is the whole difficulty and is the reverse of the one an edit of
 * rights needs. There the codes of the object moved and the audience of the object is who has to hear
 * about it; here the object stands exactly as it was, and a record published to its codes would go to
 * every member of the group but the one it is about - the leaver no longer holds SG{id}_*, and the
 * joiner is the only member for whom anything happened at all. So a membership record is addressed to
 * its user personally and to nobody else.
 *
 * The removal of the whole group is the same rule over many users at once. Its record cannot be
 * addressed by the codes of the root either: the group is going, so every one of its members is about
 * to stop holding SG{id}_*, and a record published to those codes would be idle for everyone by the
 * time it is read. It is addressed to the members personally, read out of the relations of the group
 * while they are still there - the handler stands before the deletion of both the storage and the rows.
 *
 * The removal reaches further than the storage of the group, which is why it is the one case here that
 * names more than one object. The codes SG{id}_* are cleared over the whole table of rights, so every
 * object of every other storage that was shared with the group loses it at that instant, and no
 * recalculation of Disk touches those objects - nothing else in the contour would ever notice. They are
 * read while the rows still name them and are told of one by one, up to a ceiling above which one record
 * per storage says the same thing at a hundredth of the cost.
 *
 * Nothing here is allowed to break the operation it reports. A record that was not written costs the
 * client one reconciliation; an exception raised here would cost the user the membership itself.
 */
final class GroupAccessPublisher
{
	private const LOGGER_CHANNEL = 'sync';

	/**
	 * How many objects of other storages one removal of a group names one by one.
	 *
	 * What the rows carry is the direct grants naming the group and not the files under them - a folder
	 * shared with a workgroup is one row however deep it goes - so the count is small on any portal in
	 * ordinary use. The ceiling is the portion the background mechanisms of the contour already work in,
	 * and it is here for the portal that is not: a fan of thousands of records written inside the request
	 * that deletes the group would cost the user more than the comparison it saves the clients.
	 */
	private const OBJECTS_PER_REMOVAL = 500;

	private JournalWriter $journal;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;
	private int $objectsPerRemoval;

	public function __construct(
		?JournalWriter $journal = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
		?int $objectsPerRemoval = null,
	)
	{
		$this->journal = $journal ?? new JournalWriter();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'group access publisher');
		$this->objectsPerRemoval = max(1, $objectsPerRemoval ?? self::OBJECTS_PER_REMOVAL);
	}

	/**
	 * One user entered the group or left it.
	 *
	 * The scope is the subtree of the root and not the root alone: what the membership opened or closed
	 * is the storage of the group with everything in it, which is what the reader has to compare again.
	 */
	public function publishMembershipChanged(int $rootObjectId, int $userId): void
	{
		if ($rootObjectId <= 0 || $userId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		try
		{
			$this->journal->publishAccessChanged(
				ChangeRecord::SCOPE_SUBTREE,
				$rootObjectId,
				true,
				[],
				[AudienceResolver::personalCodeOf($userId)],
			);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the change of access by a membership of a group was not published: {message}',
				[
					'message' => $exception->getMessage(),
					'rootObjectId' => $rootObjectId,
					'userId' => $userId,
					'exception' => $exception,
				],
			);
		}
	}

	/**
	 * The group is being deleted, together with its storage and the rights that named it.
	 *
	 * The records are published before any of that: the roots are still objects the portal can answer
	 * for, the relations of the group still name the users they are for, and the rows of the rights still
	 * say which objects of other storages the removal reaches.
	 *
	 * The storage of the group is one record for all its members - the boundary moved for the same
	 * subtree and on the same instant, and a record per member would say the same thing as many times as
	 * the group has members.
	 *
	 * @param int $storageId the storage of the group, whose own objects are left out of the fan below:
	 *        the record on its root already answers for the whole of it.
	 */
	public function publishGroupRemoved(int $rootObjectId, int $groupId, int $storageId): void
	{
		if ($rootObjectId <= 0 || $groupId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		try
		{
			$memberCodes = $this->personalCodesOfMembers($groupId);

			// The codes of the root go in on top of these, added by the writer. They reach whoever holds
			// the storage of the group by a sharing of their own, which the removal takes away as well.
			$this->journal->publishAccessChanged(
				ChangeRecord::SCOPE_SUBTREE,
				$rootObjectId,
				true,
				$memberCodes,
			);

			$this->publishObjectsSharedWithTheGroup($groupId, $storageId, $memberCodes);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the change of access by the removal of a group was not published: {message}',
				[
					'message' => $exception->getMessage(),
					'rootObjectId' => $rootObjectId,
					'groupId' => $groupId,
					'exception' => $exception,
				],
			);
		}
	}

	/**
	 * The objects of other storages the group was given a right on.
	 *
	 * Each of them is a subtree of its own: a right written on a folder is what everything under it is
	 * read by, so what the members are about to lose is the folder with its whole contents.
	 *
	 * Only the members are addressed. The codes the objects carry now belong to their owner and to
	 * whoever else was shared them, and for those nothing moved at all - uniting the record with them
	 * would send a whole storage to compare a subtree over a change that was never theirs.
	 *
	 * @param string[] $memberCodes personal codes of the members, as the record of the root uses them
	 */
	private function publishObjectsSharedWithTheGroup(int $groupId, int $storageId, array $memberCodes): void
	{
		if ($memberCodes === [])
		{
			// Nobody held the codes of the group, so nobody loses them.
			return;
		}

		$objectIds = $this->objectsCarryingCodesOfTheGroup($groupId, $storageId, $this->objectsPerRemoval + 1);
		if (count($objectIds) > $this->objectsPerRemoval)
		{
			$this->publishStoragesSharedWithTheGroup($groupId, $storageId, $memberCodes);

			return;
		}

		foreach ($objectIds as $objectId)
		{
			$this->journal->publishAccessChanged(ChangeRecord::SCOPE_SUBTREE, $objectId, true, [], $memberCodes);
		}
	}

	/**
	 * The same change told once per storage instead of once per object.
	 *
	 * A record on the root of a storage says "look again at everything of mine you hold", and what a
	 * member of the deleted group holds there is exactly what the group was shared. So the answer is the
	 * same one, and the price of it is the number of storages rather than the number of grants.
	 *
	 * @param string[] $memberCodes
	 */
	private function publishStoragesSharedWithTheGroup(int $groupId, int $storageId, array $memberCodes): void
	{
		$rootIds = $this->rootsOfStoragesCarryingCodesOfTheGroup($groupId, $storageId);

		$this->logger->warning(
			'the removal of a group reached more objects than are told of one by one, {storages} storages answer for them',
			['groupId' => $groupId, 'storages' => count($rootIds), 'ceiling' => $this->objectsPerRemoval],
		);

		foreach ($rootIds as $rootId)
		{
			$this->journal->publishAccessChanged(ChangeRecord::SCOPE_SUBTREE, $rootId, true, [], $memberCodes);
		}
	}

	/**
	 * @return int[]
	 */
	private function objectsCarryingCodesOfTheGroup(int $groupId, int $storageId, int $limit): array
	{
		$rows = RightTable::query()
			->setSelect(['OBJECT_ID'])
			->whereIn('ACCESS_CODE', self::codesOfGroup($groupId))
			->where('OBJECT.STORAGE_ID', '!=', $storageId)
			->setDistinct()
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['OBJECT_ID'], $rows);
	}

	/**
	 * @return int[]
	 */
	private function rootsOfStoragesCarryingCodesOfTheGroup(int $groupId, int $storageId): array
	{
		$rows = RightTable::query()
			->setSelect(['ROOT_OBJECT_ID' => 'OBJECT.STORAGE.ROOT_OBJECT_ID'])
			->whereIn('ACCESS_CODE', self::codesOfGroup($groupId))
			->where('OBJECT.STORAGE_ID', '!=', $storageId)
			->setDistinct()
			->fetchAll()
		;

		return array_values(array_filter(array_map(
			static fn (array $row): int => (int)$row['ROOT_OBJECT_ID'],
			$rows,
		)));
	}

	/**
	 * The codes of the rights the removal of the group clears - the three roles that carry a membership,
	 * which is the set the handler deletes by.
	 *
	 * @return string[]
	 */
	private static function codesOfGroup(int $groupId): array
	{
		$prefix = AccessCodeEnum::SOCNET_GROUP->value . $groupId . '_';

		return array_map(
			static fn (string $role): string => $prefix . $role,
			UserToGroupTable::getRolesMember(),
		);
	}

	/**
	 * The members of the group, as personal codes.
	 *
	 * Only the roles that carry membership: a request to join and an invitation left unanswered sit in
	 * the same table and never opened the storage of the group to anyone.
	 *
	 * @return string[]
	 */
	private function personalCodesOfMembers(int $groupId): array
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$rows = UserToGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', $groupId)
			->whereIn('ROLE', UserToGroupTable::getRolesMember())
			->setDistinct()
			->fetchAll()
		;

		return array_map(
			static fn (array $row): string => AudienceResolver::personalCodeOf((int)$row['USER_ID']),
			$rows,
		);
	}
}
