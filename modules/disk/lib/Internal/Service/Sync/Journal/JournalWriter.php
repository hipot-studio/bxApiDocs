<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Repository\Sync\SyncLogRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Disk\Internals\ObjectTable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single point at which anything reaches the journal of changes.
 *
 * Whoever found the change - a domain method, a background mechanism, the comparison of the access
 * codes of a pair - hands it over here, and nothing else writes a row. What the writer owes in return
 * is the same for every one of them: the change is normalised to the real object, the fan of a cascade
 * is suppressed, the audience is worked out and the record is published together with its codes.
 *
 * Three rules hold across every method below.
 *
 * A record nobody is addressed by is not published. The codes of a record are what a personal page is
 * filtered by, so a record without a single code lands in a journal that is read by nobody and is
 * cleaned up by a retention it only made larger.
 *
 * The journal never breaks the mutation it reports. A record that was not written costs its client one
 * reconciliation; an exception raised here would cost the user the file operation itself.
 *
 * The insert is single and carries no transaction of its own. Domain mutations of Disk run inside
 * cascades of unbounded length, and a transaction opened here would join one of them and hold its rows
 * for as long as it lasts.
 */
final class JournalWriter
{
	private const LOGGER_CHANNEL = 'sync';

	private SyncLogRepository $repository;
	private AudienceResolver $audience;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;

	public function __construct(
		?SyncLogRepository $repository = null,
		?AudienceResolver $audience = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->repository = $repository ?? new SyncLogRepository();
		$this->audience = $audience ?? new AudienceResolver();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'journal writer');
	}

	/**
	 * An object changed and its card is to be assembled on reading, if the object is still visible to
	 * the reader by then.
	 *
	 * @param string[]|null $changedFields names of the fields the mutation touched
	 * @param bool $isCascadeRoot false for an object a cascade reached rather than started on. Such an
	 *        object publishes nothing: the record of the root already tells the client that the subtree
	 *        moved, and a folder of ten thousand children would otherwise write ten thousand rows that
	 *        say the same thing.
	 */
	public function publishObjectChange(
		BaseObject $object,
		ChangeType $changeType,
		?int $authorId = null,
		?array $changedFields = null,
		bool $isCascadeRoot = true,
	): void
	{
		if (!$isCascadeRoot)
		{
			return;
		}

		if (!$changeType->isPublishableByDomain())
		{
			$this->logger->warning(
				'a change type of the writer was published as a change of an object: {changeType}',
				['changeType' => $changeType->value],
			);

			return;
		}

		$this->publish(function () use ($object, $changeType, $authorId, $changedFields): ChangeRecord {
			$realObjectId = (int)$object->getRealObjectId();

			return ChangeRecord::objectChange(
				$changeType,
				self::resourceTypeOfModel($object),
				$realObjectId,
				$this->audience->forRealObject($realObjectId),
				$authorId,
				$changedFields,
			);
		});
	}

	/**
	 * The object left the sight of its whole audience, and the caller has established that the last
	 * source of its visibility is the one that disappeared.
	 *
	 * A tombstone is an order to forget, so it is published once the disappearance is a fact and not
	 * before it: an operation that failed halfway would otherwise take an object out of the memory of
	 * the client while the portal goes on holding it, and nothing in the contour looks for that.
	 *
	 * @param string[]|null $audienceCodes the audience of the record, read by {@see captureTombstoneAudience()}
	 *        while the sources it is worked out from were still there. A caller that deletes before it
	 *        publishes has to hand them over: read after the deletion they are empty, and a record
	 *        without a single code is not published at all.
	 */
	public function publishTombstone(
		BaseObject $object,
		TombstoneReason $reason,
		?int $authorId = null,
		?array $audienceCodes = null,
	): void
	{
		$this->publishTombstoneOfGoneObject(
			self::resourceTypeOfModel($object),
			(int)$object->getRealObjectId(),
			$reason,
			$authorId,
			$audienceCodes,
		);
	}

	/**
	 * The same order to forget, for an object no model is left of.
	 *
	 * A cascade that broke halfway owes a record to everything it did take away, and by then the rows
	 * those models were built from are deleted. Such a caller carries the kind and the identifier it
	 * read while the object was still there, together with the audience it took on the root of the
	 * cascade: read here both would be empty.
	 *
	 * @param string[]|null $audienceCodes
	 * @return bool whether the record reached the journal {@see publish()}
	 */
	public function publishTombstoneOfGoneObject(
		string $resourceType,
		int $realObjectId,
		TombstoneReason $reason,
		?int $authorId = null,
		?array $audienceCodes = null,
	): bool
	{
		return $this->publish(
			fn (): ChangeRecord => ChangeRecord::tombstone(
				$resourceType,
				$realObjectId,
				$reason,
				$audienceCodes ?? $this->audience->forRealObject($realObjectId),
				$authorId,
			),
		);
	}

	/**
	 * The audience of a tombstone, taken while the object is still whole.
	 *
	 * The rights of the object and the rows of its interaction set are what the addressees are worked
	 * out from, and a deletion tears both down, so the two halves of the publication fall on either
	 * side of it: the audience is read here, the record is written once the deletion has succeeded.
	 *
	 * Null on a portal the contour is off on and on a reading that failed. Neither is allowed to break
	 * the deletion, and the publication that follows falls back to reading the audience itself - by
	 * which time it finds nobody, and no record is written.
	 *
	 * @return string[]|null
	 */
	public function captureTombstoneAudience(BaseObject $object): ?array
	{
		if (!$this->feature->isEnabled())
		{
			return null;
		}

		try
		{
			return $this->audience->forRealObject((int)$object->getRealObjectId());
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the audience of a tombstone was not read before the deletion: {message}',
				[
					'message' => $exception->getMessage(),
					'objectId' => $object->getRealObjectId(),
					'exception' => $exception,
				],
			);

			return null;
		}
	}

	/**
	 * The object left the sight of one user while staying where it was for everyone else: the row of
	 * the interaction set aged out, or the object was taken out of the set.
	 *
	 * Nothing is published to a user who still sees the object by the rights of the module - the
	 * interaction was not the source their visibility stood on, and the tombstone would be untrue.
	 *
	 * @return bool whether the record reached the journal {@see publish()}. A caller that takes the
	 *         source of the visibility away afterwards has to wait for true: the record is the only
	 *         thing that tells the user the object left them, and nothing else looks for one that was
	 *         never written.
	 */
	public function publishPersonalTombstone(int $realObjectId, int $userId, TombstoneReason $reason): bool
	{
		return $this->publish(function () use ($realObjectId, $userId, $reason): ?ChangeRecord {
			$resourceType = $this->resourceTypeOf($realObjectId);
			if ($resourceType === null || $this->audience->hasRightsAccess($realObjectId, $userId))
			{
				return null;
			}

			return ChangeRecord::tombstone(
				$resourceType,
				$realObjectId,
				$reason,
				[AudienceResolver::personalCodeOf($userId)],
			);
		});
	}

	/**
	 * The user touched the object and it entered their interaction set. It is published as an ordinary
	 * change so that the client fetches a card it has never held.
	 *
	 * Only the first entry belongs here: a set that is touched again on every opening would otherwise
	 * republish the same object for as long as the user keeps working with it. Whether the entry is the
	 * first one is known to the caller writing the row and is not asked again here.
	 *
	 * A user who already sees the object by rights is addressed by nothing at all: the record carries no
	 * code of the rights, because it is about one user, and no personal code, because personal codes go
	 * only to those the rights do not reach. Such a record is dropped rather than published empty.
	 */
	public function publishInteractionEntered(int $realObjectId, int $userId): void
	{
		$this->publish(function () use ($realObjectId, $userId): ?ChangeRecord {
			$resourceType = $this->resourceTypeOf($realObjectId);
			if ($resourceType === null || $this->audience->hasRightsAccess($realObjectId, $userId))
			{
				return null;
			}

			return ChangeRecord::objectChange(
				ChangeType::Updated,
				$resourceType,
				$realObjectId,
				[AudienceResolver::personalCodeOf($userId)],
				authorId: $userId,
			);
		});
	}

	/**
	 * A boundary of visibility moved. The record carries no operation: it reports where the reader is to
	 * look again rather than what an object now holds.
	 *
	 * A scope naming an object the portal can no longer answer for publishes nothing at all: what the
	 * client is handed is an identifier together with the kind of what it names, so a record holding one
	 * without the other is one nobody could be given.
	 *
	 * @param string $scope one of the scopes of {@see ChangeRecord}
	 * @param string[] $codes the codes the change is published to. For an edit of the rights of an
	 *        object these are the codes as they stood before it: the writer unites them with the ones
	 *        the object carries now, because after the recalculation the set holds no code of whoever
	 *        lost access, and a record published to the current set alone would be idle on exactly the
	 *        revocation the signal exists for.
	 * @param string[] $personalCodes set when the boundary that moved belongs to particular users rather
	 *        than to an object - their access codes changed, or a link carried the object to them alone,
	 *        and nothing about the object itself did. Such a record is addressed to those users and to
	 *        nobody else: the rest of the audience of the object would be sent to fetch a subtree that
	 *        has not changed for them. Several of them share one record, because a personal page is
	 *        assembled by intersecting the codes of a record with the codes of its reader, so a record
	 *        carrying N personal codes reaches exactly those N readers and each of them once. The slot
	 *        is named after what it does to the addressing and not after the shape of what goes in: it
	 *        addresses the record to exactly these codes and to nothing else, so a caller whose audience
	 *        is already a code of the portal - the codes a link carries, say - hands it over here too.
	 * @param string[]|null $currentCodes the codes the object carries now, for a caller that has read
	 *        them already. The union is the same either way; what the slot saves is the reading, and a
	 *        reading of them is not one statement but a walk up the closure of the tree to the nearest
	 *        object carrying a readable right of its own. Null is the absence of such a reading and not
	 *        an empty set: a caller that looked and found no code at all says so with an empty array,
	 *        and the writer looks no further.
	 * @return bool whether the record reached the journal {@see publish()}
	 */
	public function publishAccessChanged(
		string $scope,
		?int $scopeObjectId,
		bool $affectsDescendants,
		array $codes,
		array $personalCodes = [],
		?array $currentCodes = null,
	): bool
	{
		return $this->publish(
			function () use (
				$scope,
				$scopeObjectId,
				$affectsDescendants,
				$codes,
				$personalCodes,
				$currentCodes,
			): ?ChangeRecord {
				$resourceType = $scopeObjectId === null ? null : $this->resourceTypeOf($scopeObjectId);
				if ($scopeObjectId !== null && $resourceType === null)
				{
					// The kind went with the object, and a scope names an object together with its
					// kind. Such a record is refused on the way out and passed over by the reading,
					// which cannot tell it from a row of an older shape, so the loss belongs here and
					// named. Nothing goes with it: a root that is not an object opened nothing.
					$this->logger->warning(
						'a change of access was not published, its object is gone: {scopeObjectId}',
						['scope' => $scope, 'scopeObjectId' => $scopeObjectId],
					);

					return null;
				}

				return ChangeRecord::accessChange(
					$scope,
					$scopeObjectId,
					$affectsDescendants,
					$this->accessChangeAudience($codes, $scopeObjectId, $personalCodes, $currentCodes),
					$resourceType,
				);
			},
		);
	}

	/**
	 * @param string[] $codes
	 * @param string[] $personalCodes
	 * @param string[]|null $currentCodes
	 * @return string[]
	 */
	private function accessChangeAudience(
		array $codes,
		?int $scopeObjectId,
		array $personalCodes,
		?array $currentCodes,
	): array
	{
		if ($personalCodes !== [])
		{
			return self::united($codes, $personalCodes);
		}

		if ($currentCodes === null)
		{
			$currentCodes = $scopeObjectId === null ? [] : $this->audience->rightsCodesOf($scopeObjectId);
		}

		return self::united($codes, $currentCodes);
	}

	/**
	 * @param callable(): ?ChangeRecord $build
	 * @return bool false when a record that was meant to be written was lost - the insert refused it or
	 *         the building of it raised. A record deliberately dropped is not a loss and answers true:
	 *         the contour is off, nobody is addressed by the record, the object it names is gone. A
	 *         caller that undoes its own state on false would otherwise undo it on every call that had
	 *         nothing to publish in the first place.
	 */
	private function publish(callable $build): bool
	{
		if (!$this->feature->isEnabled())
		{
			return true;
		}

		try
		{
			$record = $build();
			if ($record === null || $record->accessCodes === [])
			{
				return true;
			}

			return $this->write($record);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the journal did not take a record: {message}',
				['message' => $exception->getMessage(), 'exception' => $exception],
			);

			return false;
		}
	}

	private function write(ChangeRecord $record): bool
	{
		$result = $record->isAccessChange()
			? $this->repository->addAccessChange(
				$record->changeType->value,
				(string)$record->accessScope,
				$record->affectsDescendants,
				$record->accessCodes,
				$record->resourceType,
				$record->resourceId,
				$record->scopeObjectId,
			)
			: $this->repository->addObjectChange(
				$record->changeType->value,
				(string)$record->operation,
				(string)$record->resourceType,
				(int)$record->resourceId,
				$record->accessCodes,
				$record->authorId,
				$record->changedFields,
				$record->tombstoneReason?->value,
			)
		;

		if (!$result->isSuccess())
		{
			$this->logger->error(
				'the journal did not take a record of {changeType}: {errors}',
				[
					'changeType' => $record->changeType->value,
					'errors' => implode('; ', $result->getErrorMessages()),
				],
			);

			return false;
		}

		return true;
	}

	/**
	 * A link to a folder is a folder and a link to a file is a file, so the class of the model answers
	 * for the real object as well and no row has to be read for it.
	 */
	private static function resourceTypeOfModel(BaseObject $object): string
	{
		return $object instanceof Folder ? ChangeRecord::RESOURCE_FOLDER : ChangeRecord::RESOURCE_FILE;
	}

	/**
	 * Null for an object that is no longer there: a record about a resource whose kind is unknown says
	 * nothing a client could act on.
	 */
	private function resourceTypeOf(int $realObjectId): ?string
	{
		$row = ObjectTable::getRow([
			'select' => ['TYPE'],
			'filter' => ['=ID' => $realObjectId],
		]);
		if ($row === null)
		{
			return null;
		}

		return (int)$row['TYPE'] === ObjectTable::TYPE_FOLDER
			? ChangeRecord::RESOURCE_FOLDER
			: ChangeRecord::RESOURCE_FILE
		;
	}

	/**
	 * @param string[] $codes
	 * @param string[] $other
	 * @return string[]
	 */
	private static function united(array $codes, array $other): array
	{
		return array_values(array_unique(array_merge($codes, $other)));
	}
}
