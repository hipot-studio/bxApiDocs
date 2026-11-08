<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\CapabilitiesDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ObjectCardDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeType;

/**
 * What this portal declares it can do, assembled in one place.
 *
 * The answer is the client's whole basis for planning its work, so nothing here is a literal
 * repeated from elsewhere: the page ceilings and the polling bounds come from {@see SyncLimits},
 * the card sections from {@see SectionsStructure}. Were they copied, the perceived contract and
 * the enforced one would drift apart - the portal would promise a section the validation refuses,
 * or a page size it trims.
 */
final class CapabilitiesProvider
{
	/**
	 * The version of the contour's schema as a whole. It moves when the shape of the answers
	 * changes, not when a number in them does.
	 */
	public const SCHEMA_VERSION = 1;

	/** @var string[] */
	public const ENTITY_TYPES = [ObjectCardDto::TYPE_FILE, ObjectCardDto::TYPE_FOLDER];

	/**
	 * Published as false rather than left out: an older portal answers without the field at all,
	 * and a client has to be able to tell "this portal will never tell me about a change" from
	 * "this portal is too old to say".
	 */
	private const CHANGE_NOTIFICATION_SUPPORTED = false;

	private SyncLimits $limits;

	public function __construct(?SyncLimits $limits = null)
	{
		$this->limits = $limits ?? new SyncLimits();
	}

	public function get(): CapabilitiesDto
	{
		$capabilities = new CapabilitiesDto();

		$capabilities->schemaVersion = self::SCHEMA_VERSION;
		$capabilities->entityTypes = self::ENTITY_TYPES;
		$capabilities->changeTypes = self::getChangeTypes();
		$capabilities->pageSizeMax = $this->limits->getPageSizeMax();
		$capabilities->pageSizeMaxHeavy = $this->limits->getPageSizeMaxHeavy();
		$capabilities->journalRetentionDays = $this->limits->getJournalRetentionDays();
		$capabilities->interactionWindowDays = $this->limits->getInteractionWindowDays();
		$capabilities->safeWindowSeconds = $this->limits->getSafeWindowSeconds();
		$capabilities->pollIntervalMinSeconds = $this->limits->getPollIntervalMinSeconds();
		$capabilities->pollIntervalRecommendedSeconds = $this->limits->getPollIntervalRecommendedSeconds();
		$capabilities->pollIntervalMaxSeconds = $this->limits->getPollIntervalMaxSeconds();
		$capabilities->cardSections = self::describeCardSections();
		$capabilities->changeNotificationSupported = self::CHANGE_NOTIFICATION_SUPPORTED;

		return $capabilities;
	}

	/**
	 * The vocabulary of change is published out of the enumeration the journal writes, and is not a
	 * list of its own standing beside it. A method rather than a constant because that is the only way
	 * to take it from there: a value the journal can write and the answer never names is a branch no
	 * client takes, and one the answer names and nothing ever writes is a branch no client reaches.
	 *
	 * @return string[]
	 */
	public static function getChangeTypes(): array
	{
		return array_map(static fn (ChangeType $type): string => $type->value, ChangeType::cases());
	}

	/**
	 * @return array<string, bool>
	 */
	private static function describeCardSections(): array
	{
		$served = SectionsStructure::getAvailableSections();

		$sections = [];
		foreach (SectionsStructure::getDeclaredSections() as $section)
		{
			$sections[$section] = in_array($section, $served, true);
		}

		return $sections;
	}
}
