<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rows of the interaction set that go with the object they name, told to the journal one addressee
 * at a time.
 *
 * A file the user holds by an interaction alone is invisible to everything else this contour publishes.
 * The record of a cascade is written on its root and addressed to whoever holds the root, and the client
 * applies what follows from it along the path of an object - which a file carried by an interaction has
 * none of, because a path is walked through folders the reader can see. A reconciliation started on that
 * root answers with an empty page and names no object at all. So without this point such a file would
 * leave the portal without a word to the one user it was visible to, and the aging would never meet it
 * either: the row of the set goes with the object.
 *
 * The point is the cleanup of the set rather than the writer of the cascade because the audience is
 * known here row by row and no subtree has to be walked for it.
 *
 * Two rules hold.
 *
 * The publication goes before the rows are deleted, never after: the rows are the whole record of who
 * held the object without rights, and the audience is gone with them. It also goes before the rights of
 * the object are torn down, because the question "does this user see the object by rights as well" is
 * answered from them ({@see JournalWriter::publishPersonalTombstone()}).
 *
 * Nothing here is allowed to break the deletion it reports. A record that was not written costs the
 * client one reconciliation; an exception raised here would cost the user the deletion itself.
 */
final class TrackedSetCleanupPublisher
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
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'tracked set cleanup');
	}

	/**
	 * The object is about to be deleted and its rows of the set with it: everyone the interaction was
	 * carrying it to is buried by a tombstone of their own.
	 *
	 * A record per addressee, because a personal code names one user and a tombstone addressed to the
	 * codes of the object would tell its whole audience to drop a file most of them still hold.
	 *
	 * The holders the rights of the object do reach never come out of the resolver, so the loop never
	 * meets one. The writer asks the rights about every user it is handed, and a file a hundred
	 * colleagues have opened would answer that question a hundred times over; the resolver settles it
	 * for the whole set in one pass. It is asked for that half of the audience rather than for the
	 * whole audience less the rights, exactly as the publication of a link asks for it - the difference
	 * would read the codes of the object twice - and asked for it by identifier, which is what the
	 * writer is addressed by and what keeps the rows of the set read once as well.
	 *
	 * The resolver answers that half by reading the set first and the codes of the object only after
	 * it, which is what the file this branch meets most often - one nobody keeps in a set - is worth:
	 * a single statement over the set, and no walk up the closure of the tree behind it.
	 *
	 * @param int $realObjectId the object the rows name, which is the real one - the set keeps no rows
	 *        of a link.
	 */
	public function publishHoldersLosingTheObject(int $realObjectId): void
	{
		if ($realObjectId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		try
		{
			foreach ($this->audience->idsOfInteractionOnlyHolders($realObjectId) as $userId)
			{
				$this->journal->publishPersonalTombstone(
					$realObjectId,
					$userId,
					TombstoneReason::InteractionRemoved,
				);
			}
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the removal of the interaction set of an object was not published: {message}',
				['message' => $exception->getMessage(), 'objectId' => $realObjectId, 'exception' => $exception],
			);
		}
	}
}
