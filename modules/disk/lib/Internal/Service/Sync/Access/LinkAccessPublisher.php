<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Access;

use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\AudienceResolver;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeRecord;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A link stopped granting reading, or started granting it again, told to the journal.
 *
 * This is the one source of visibility whose state the module checks in bulk on the reading rather
 * than at the moment it moves (ALG-05): a row of the interaction set is confirmed by a link that is
 * alive on the object, and a link that is gone stops confirming it. That check is what makes the
 * signal necessary rather than redundant. Without a record nobody is told to compare anything, and
 * the comparison alone would answer "you still see it" for exactly as long as the link stood - which
 * is up to the instant it was switched off and no longer.
 *
 * Both directions are published, as they are for the rights of an object and for a membership of a
 * workgroup. A record of a boundary reports where to look again and never what an object holds, so it
 * carries a loss and a return equally well - and a return published by nothing at all would be a
 * return the client never learns of: the entry of an object into the interaction set is published
 * once, when the row is written, and a link relit years later writes no row.
 *
 * What counts as granting reading is read here exactly as the confirmation of sources reads it - an
 * external link that exists and whose death time is empty or still ahead, a unified link whose stored
 * level is not the denied one. A rule of its own here would make the event and the reconciliation
 * disagree about the same field, which is the one thing the pair cannot afford. It follows that only
 * a move across that line is published: prolonging a living link or moving a unified link between
 * reading and editing takes no visibility away and gives none back.
 *
 * The addressees are the users a link carried and the rights do not. A user holding the object by the
 * rights of the module is untouched when a link on it dies: their visibility never stood on the link,
 * and a record would send the whole audience of the object to compare a boundary that did not move for
 * them. So the codes of the object are deliberately left out and every addressee is named personally.
 *
 * All of them share one record. A personal page is assembled by intersecting the codes of a record with
 * the codes of its reader, so a record carrying the personal codes of every holder is handed to each of
 * them exactly as a record of their own would be - and one switching off of a link costs the journal one
 * row instead of a row per holder, on a set nothing bounds.
 *
 * The set of addressees is read as a whole and not narrowed to those who lost the last source they
 * had. An addressee who kept another one - a second link, an attachment of the object into an entity
 * of another module - is told to look again and is answered that the object is still theirs. That
 * costs one comparison and never a loss, while a narrowing computed here would have to repeat the
 * confirmation of every source the reading already performs.
 *
 * The scope is the object itself and the descendants are not named. A link is granted on the object it
 * was made for, and what the addressee holds by it is that object - the interaction set carries rows
 * of objects and never of subtrees.
 *
 * Nothing here is allowed to break the operation it reports. A record that was not written costs the
 * client one reconciliation; an exception raised here would cost the user the change of the link itself.
 */
final class LinkAccessPublisher
{
	private const LOGGER_CHANNEL = 'sync';

	private AudienceResolver $audience;
	private JournalWriter $journal;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;

	public function __construct(
		?AudienceResolver $audience = null,
		?JournalWriter $journal = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->audience = $audience ?? new AudienceResolver();
		$this->journal = $journal ?? new JournalWriter();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'link access publisher');
	}

	/**
	 * An external link is gone from the object.
	 *
	 * Published after the row has been taken away and not before it: a client that reconciles on the
	 * record must be answered by the state the record reports, and a link still standing would confirm
	 * the very rows the addressee is being told to give up. A deletion that failed reports nothing at
	 * all, for the same reason in reverse.
	 *
	 * A link whose lifetime was already spent is reported as well, though its boundary moved when the
	 * lifetime ran out rather than now. Nothing was published then - time passing is not an operation
	 * anyone could hang a record on - and the deletion is the last trigger such a link will ever get,
	 * so the news travels late rather than not at all.
	 */
	public function publishExternalLinkRevoked(int $objectId): void
	{
		if ($objectId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		$this->publishBoundaryMoved($objectId, 'an external link');
	}

	/**
	 * The lifetime of an external link has been written over.
	 *
	 * A lifetime is how a link is switched off without being deleted: a death time in the past leaves
	 * the row where it was and stops it confirming anything, and taking that death time away lights it
	 * again. Only a move across the line is published - a living link prolonged goes on granting exactly
	 * what it granted.
	 *
	 * @param DateTime|null $before the death time the link carried, null for a link without one
	 * @param DateTime|null $after the death time it carries now
	 */
	public function publishExternalLinkLifetimeChanged(int $objectId, ?DateTime $before, ?DateTime $after): void
	{
		if ($objectId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		if (self::isLifetimeAhead($before) === self::isLifetimeAhead($after))
		{
			return;
		}

		$this->publishBoundaryMoved($objectId, 'the lifetime of an external link');
	}

	/**
	 * The level of the unified link of the object as it stands now, read before it is written over.
	 *
	 * The level is what tells a move of the boundary from an ordinary edit, and it is gone the moment
	 * the new one is stored, so it is read here. A portal the contour is off on reads nothing: the
	 * question is asked on every change of the level, and the answer is of no use to anyone there.
	 */
	public function captureUnifiedLevel(int $objectId): ?UnifiedLinkAccessLevel
	{
		if ($objectId <= 0 || !$this->feature->isEnabled())
		{
			return null;
		}

		try
		{
			return UnifiedLinkAccessRepository::getByObjectId($objectId);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the level of the unified link was not read before the change: {message}',
				['message' => $exception->getMessage(), 'objectId' => $objectId, 'exception' => $exception],
			);

			return null;
		}
	}

	/**
	 * The level of the unified link has been written over.
	 *
	 * Only a level that starts or stops granting reading is published. A move between reading and
	 * editing leaves the object exactly as visible as it was, and an object that carried no level at
	 * all was open to nobody by a unified link.
	 *
	 * @param UnifiedLinkAccessLevel|null $before the level {@see captureUnifiedLevel} read, null when
	 *        the object carried none - such an object was never opened by a unified link at all.
	 */
	public function publishUnifiedLinkLevelChanged(
		int $objectId,
		?UnifiedLinkAccessLevel $before,
		UnifiedLinkAccessLevel $after,
	): void
	{
		if ($objectId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		if (($before?->canRead() === true) === $after->canRead())
		{
			return;
		}

		$this->publishBoundaryMoved($objectId, 'a unified link');
	}

	/**
	 * Whether a death time still leaves the link granting reading, as the confirmation of sources
	 * decides it: an empty death time or one that is still ahead.
	 */
	private static function isLifetimeAhead(?DateTime $deathTime): bool
	{
		return $deathTime === null || $deathTime->getTimestamp() > time();
	}

	private function publishBoundaryMoved(int $objectId, string $source): void
	{
		try
		{
			$personalCodes = $this->personalCodesOfLinkOnlyHolders($objectId);
			if ($personalCodes === [])
			{
				// An object nobody held by a link alone has nobody to tell, and the emptiness is checked
				// here rather than left to the writer: an empty set of personal codes is how the writer
				// is told that the boundary belongs to the object, and it would address the record to
				// the whole audience of the object - none of which lost anything.
				return;
			}

			$this->journal->publishAccessChanged(
				ChangeRecord::SCOPE_OBJECT,
				$objectId,
				false,
				[],
				$personalCodes,
			);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the change of access by {source} was not published: {message}',
				[
					'source' => $source,
					'message' => $exception->getMessage(),
					'objectId' => $objectId,
					'exception' => $exception,
				],
			);
		}
	}

	/**
	 * The personal codes of the users the interaction set carries the object to and the rights of the
	 * object do not reach. They are the only ones a link could have been the source of visibility for,
	 * and the resolver hands that half of the audience over on its own - asked for it as the difference
	 * of the whole audience and the rights, it would read the codes of the object a second time.
	 *
	 * @return string[]
	 */
	private function personalCodesOfLinkOnlyHolders(int $objectId): array
	{
		return $this->audience->personalCodesOfInteractionOnlyHolders($objectId);
	}
}
