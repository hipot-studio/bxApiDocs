<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * Everything a client of the synchronisation contour needs before it makes its first working call.
 *
 * Built by hand from the portal's settings rather than mapped from a stored entity, so it declares
 * neither an ORM entity nor a mapper. Property order is the order of the published answer.
 */
final class CapabilitiesDto extends Dto
{
	public int $schemaVersion;

	/** @var string[] */
	public array $entityTypes;

	/** @var string[] */
	public array $changeTypes;

	public int $pageSizeMax;

	public int $pageSizeMaxHeavy;

	public int $journalRetentionDays;

	/**
	 * How long an object a user merely touched stays visible to them on that interaction alone. Once
	 * the window runs out the object leaves the sight of that user and is announced as a tombstone, so
	 * a client planning against this number knows the removal is the portal forgetting an interaction
	 * rather than anything happening to the object.
	 */
	public int $interactionWindowDays;

	public int $safeWindowSeconds;

	public int $pollIntervalMinSeconds;

	public int $pollIntervalRecommendedSeconds;

	public int $pollIntervalMaxSeconds;

	/**
	 * Every section the schema declares, each with whether this portal fills it. A section named
	 * here as false is refused when asked for, so that a client can tell "nothing to show" from
	 * "this portal cannot answer that yet".
	 *
	 * @var array<string, bool>
	 */
	public array $cardSections;

	public bool $changeNotificationSupported;
}
