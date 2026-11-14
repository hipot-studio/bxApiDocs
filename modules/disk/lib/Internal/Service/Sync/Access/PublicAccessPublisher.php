<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Access;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeType;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An operation over the own public link of an object, told to the journal as a change of its public
 * access.
 *
 * The type names the operation and not the resulting state of the card: a lifetime moved into the past
 * is an update although the link stops opening, and the deletion of the own link of an object under a
 * public folder is a disabling although the object stays public by inheritance. The client takes the
 * state from the card of the record.
 *
 * Only a link that makes an object public for synchronisation is published ({@see PublicLinkRule}), the
 * same rule the section of the card is assembled by. The portal policy of opening links is not read: it
 * belongs to the capabilities, and a switch of it is no operation over any link.
 *
 * Nothing here is allowed to break the operation it reports.
 */
final class PublicAccessPublisher
{
	private const LOGGER_CHANNEL = 'sync';

	private JournalWriter $journal;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;

	public function __construct(
		?JournalWriter $journal = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->journal = $journal ?? new JournalWriter();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'public access publisher');
	}

	public function publishEnabled(ExternalLink $link, ?int $authorId = null): void
	{
		$this->publish($link, ChangeType::PublicAccessEnabled, $authorId);
	}

	/**
	 * Called by the caller only when the mode, the lifetime or the protection by a password changed.
	 */
	public function publishUpdated(ExternalLink $link, ?int $authorId = null): void
	{
		$this->publish($link, ChangeType::PublicAccessUpdated, $authorId);
	}

	public function publishDisabled(ExternalLink $link, ?int $authorId = null): void
	{
		$this->publish($link, ChangeType::PublicAccessDisabled, $authorId);
	}

	private function publish(ExternalLink $link, ChangeType $changeType, ?int $authorId): void
	{
		if (!$this->feature->isEnabled())
		{
			return;
		}

		try
		{
			if (!PublicLinkRule::isPublicKind($link->getType(), $link->getScenario()?->value))
			{
				return;
			}

			$object = $link->getObject();
			if (!$object instanceof BaseObject)
			{
				return;
			}

			$this->journal->publishAccessState($object, $changeType, $authorId);
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the change of public access was not published: {message}',
				[
					'message' => $exception->getMessage(),
					'changeType' => $changeType->value,
					'linkId' => $link->getId(),
					'objectId' => $link->getObjectId(),
					'exception' => $exception,
				],
			);
		}
	}
}
