<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Access;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Card\SharingTargetReader;
use Bitrix\Disk\Internal\Service\Sync\Card\SharingTargetSource;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeType;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An edit of the explicit rights of an object, told to the journal as a change of its sharing.
 *
 * The type is chosen by comparing the targets of the object before and after the edit, read by the same
 * reader the section of the card is assembled by: the first targets create the sharing, the last ones
 * taken away delete it, any other move of the set updates it. An edit that leaves the set as it was -
 * another task for the same recipient - publishes nothing.
 *
 * Unlike the record of the boundary ({@see RightsAccessPublisher}) this one goes out at once and does not
 * wait for the recalculation of the subtree: it reports the explicit rights of the object, which are
 * already written.
 *
 * Nothing here is allowed to break the edit it reports.
 */
final class SharingAccessPublisher
{
	private const LOGGER_CHANNEL = 'sync';

	private SharingTargetSource $targets;
	private JournalWriter $journal;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;

	public function __construct(
		?SharingTargetSource $targets = null,
		?JournalWriter $journal = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->targets = $targets ?? new SharingTargetReader();
		$this->journal = $journal ?? new JournalWriter();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'sharing access publisher');
	}

	/**
	 * The targets of the object before the edit.
	 *
	 * The root of a storage has none without a read: its rights are the base rights of the storage and
	 * {@see publishIfChanged()} publishes nothing for it.
	 *
	 * @return list<array{type: string, id: ?int, bySharing: bool}>|null null when there is nothing to
	 *         compare against: the object is not saved, the contour is off or the targets could not be read
	 */
	public function captureTargets(BaseObject $object): ?array
	{
		$objectId = (int)$object->getId();
		if ($objectId <= 0 || !$this->feature->isEnabled())
		{
			return null;
		}

		try
		{
			if (!$object->getParentId())
			{
				return [];
			}

			return $this->targets->explicit($objectId);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the targets of sharing were not read before the edit of rights: {message}',
				['message' => $exception->getMessage(), 'objectId' => $objectId, 'exception' => $exception],
			);

			return null;
		}
	}

	/**
	 * @param list<array{type: string, id: ?int, bySharing: bool}>|null $before the targets
	 *        {@see captureTargets()} read before the edit
	 * @param string[] $codesBeforeEdit codes of the object read before the edit. The record is addressed
	 *        to them: after the edit the object is a checkpoint without simple rights until the
	 *        recalculation, and a recipient the sharing is taken from is still among the readers before it.
	 */
	public function publishIfChanged(BaseObject $object, ?array $before, array $codesBeforeEdit): void
	{
		if ($before === null || !$this->feature->isEnabled())
		{
			return;
		}

		try
		{
			// the rights of the root are the base rights of the storage and not a sharing
			if (!$object->getParentId())
			{
				return;
			}

			$after = $this->targets->explicit((int)$object->getId());
			$changeType = self::changeTypeOf($before, $after);
			if ($changeType === null)
			{
				return;
			}

			$this->journal->publishAccessState($object, $changeType, rightsCodes: $codesBeforeEdit);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the change of sharing was not published: {message}',
				['message' => $exception->getMessage(), 'objectId' => $object->getId(), 'exception' => $exception],
			);
		}
	}

	private static function changeTypeOf(array $before, array $after): ?ChangeType
	{
		if (self::keysOf($before) === self::keysOf($after))
		{
			return null;
		}

		if ($before === [])
		{
			return ChangeType::SharingCreated;
		}

		if ($after === [])
		{
			return ChangeType::SharingDeleted;
		}

		return ChangeType::SharingUpdated;
	}

	/**
	 * Whether a target comes from sharing counts too: it decides whether the section shows the targets
	 * to a user who may only share the object.
	 *
	 * @param list<array{type: string, id: ?int, bySharing: bool}> $targets
	 * @return string[]
	 */
	private static function keysOf(array $targets): array
	{
		$keys = array_map(
			static fn(array $target): string => $target['type'] . ':' . ($target['id'] ?? '') . ':' . (int)$target['bySharing'],
			$targets,
		);
		sort($keys, SORT_STRING);

		return $keys;
	}
}
