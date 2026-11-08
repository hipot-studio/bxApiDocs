<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Access;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\AudienceResolver;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeRecord;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A folder connected to a storage by a link, or disconnected from it, told to the journal.
 *
 * The domain point publishes one record of its own already - a change of the places of the real
 * object - and that record answers for the folder alone. A link is a place of everything lying under
 * the folder as well, and nothing in the contour ever looks at those descendants: their places move at
 * the instant the link appears or goes, and no operation touches them. What the client is left holding
 * is a file with no place at all after an invitation is accepted, and a place pointing at the parent of
 * a link that no longer exists after one is withdrawn - a path leading nowhere, which nothing repairs.
 *
 * So the boundary is published on the subtree, and the client walks it with the ordinary reconciliation
 * of two moves. No subtree is walked here: the size of the folder costs the portal nothing.
 *
 * The meaning of the event is stretched on purpose. Nothing about access moved when an invitation was
 * accepted - what moved is where the object lies - but the client is told to act by the reach of a
 * record and never by a dictionary of kinds, and a kind of its own for a move of placement would widen
 * that dictionary for a case the existing signal already carries.
 *
 * The root of the scope is the real object and never the link. The walk of a scope unfolds the subtree
 * over the closure of the physical tree, where a link to a folder has no descendants at all, so a scope
 * named by the link would answer with an empty page; the rights of the root are read on the real object
 * too, and the invited user holds them.
 *
 * The addressees are the codes of the link itself, and they go into the slot of the personal codes so
 * that the record reaches them and nobody else. Handed to the writer as the codes of the change, they
 * would be united with the ones the real object carries, and everyone who already held the shared
 * folder would be sent to walk a subtree that did not move for them - the fan the publishers of a
 * membership and of a link both refuse for the same reason.
 *
 * The codes are read off the link rather than off the object it points at, and that is what makes them
 * trustworthy at this instant: the codes of an object are those of the nearest checkpoint above it, and
 * above a link just created that checkpoint is the root of the storage of its holder, whose rows were
 * materialized long ago. The rights of the shared object itself may still be under a recalculation the
 * portal runs in the background.
 *
 * Nothing here is allowed to break the operation it reports. A record that was not written costs the
 * client one reconciliation; an exception raised here would cost the user the link itself - which is
 * why the audience is worked out inside this class and not by the caller: the writer swallows what is
 * raised under it, and the reading of the codes happens before the writer is ever entered.
 */
final class PlacementAccessPublisher
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
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'placement access publisher');
	}

	/**
	 * The folder is now connected to a storage by the given link.
	 *
	 * Published after the record the domain point writes on the real object: records are read in the
	 * order they were written, and the card of the root before the signal on its subtree is the order
	 * the client is served by.
	 */
	public function publishSubtreeConnected(int $realObjectId, int $linkId): void
	{
		if ($realObjectId <= 0 || $linkId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		try
		{
			$this->publishSubtreeMoved($realObjectId, $this->audience->rightsCodesOf($linkId), 'connected');
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the change of access by a connection of an object was not published: {message}',
				[
					'message' => $exception->getMessage(),
					'realObjectId' => $realObjectId,
					'linkId' => $linkId,
					'exception' => $exception,
				],
			);
		}
	}

	/**
	 * The codes the link carries, read while the rights they come from are still there.
	 *
	 * A deletion tears down the rights of the link before the record can be published - and it has to
	 * be published after the deletion, so that a client reconciling on it is answered by the state the
	 * record reports - so the audience is taken here, at the start of the operation. Read afterwards it
	 * is empty, and a record without a single code is not published at all.
	 *
	 * @return string[]
	 */
	public function captureCodes(int $linkId): array
	{
		if ($linkId <= 0 || !$this->feature->isEnabled())
		{
			return [];
		}

		try
		{
			return $this->audience->rightsCodesOf($linkId);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the codes of a link were not read before its deletion: {message}',
				['message' => $exception->getMessage(), 'linkId' => $linkId, 'exception' => $exception],
			);

			return [];
		}
	}

	/**
	 * The link is gone and the folder it pointed at stayed.
	 *
	 * @param string[] $capturedCodes the codes of the link as {@see captureCodes()} took them before it
	 *        was torn down.
	 */
	public function publishSubtreeDisconnected(int $realObjectId, array $capturedCodes): void
	{
		if ($realObjectId <= 0 || !$this->feature->isEnabled())
		{
			return;
		}

		try
		{
			$this->publishSubtreeMoved($realObjectId, $capturedCodes, 'disconnected');
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the change of access by a disconnection of an object was not published: {message}',
				[
					'message' => $exception->getMessage(),
					'realObjectId' => $realObjectId,
					'exception' => $exception,
				],
			);
		}
	}

	/**
	 * @param string[] $codes
	 */
	private function publishSubtreeMoved(int $realObjectId, array $codes, string $direction): void
	{
		if ($codes === [])
		{
			// The emptiness is stopped here rather than left to the writer: an empty set of personal
			// codes is how the writer is told that the boundary belongs to the object, and it would
			// address the record to the whole audience of the shared folder - none of which moved.
			$this->logger->warning(
				'a link carried no code of its own, the move of its placement is published to nobody',
				['realObjectId' => $realObjectId, 'direction' => $direction],
			);

			return;
		}

		$this->journal->publishAccessChanged(
			ChangeRecord::SCOPE_SUBTREE,
			$realObjectId,
			true,
			[],
			$codes,
		);
	}
}
