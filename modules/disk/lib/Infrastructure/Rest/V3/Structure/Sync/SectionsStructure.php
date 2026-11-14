<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The sections of an object card a listing method is asked for.
 *
 * A declared section that is not filled yet stays in the schema so that a later stage can fill it
 * without changing the base contract, and asking for it now is an explicit refusal rather than a
 * section that silently comes back empty: a client that got an empty section would have no way to
 * tell "nothing there" from "the portal cannot answer that yet". Which sections a portal serves is
 * published by disk.sync.capabilities.get.
 */
final class SectionsStructure extends Structure
{
	public const SECTION_METADATA = 'metadata';
	public const SECTION_LOCATION = 'location';
	public const SECTION_ACCESS = 'access';
	public const SECTION_RELATIONS = 'relations';

	/**
	 * @param string[] $sections
	 */
	private function __construct(
		private readonly array $sections,
	)
	{
	}

	/**
	 * Declared in the schema and served.
	 *
	 * @return string[]
	 */
	public static function getAvailableSections(): array
	{
		return [self::SECTION_METADATA, self::SECTION_LOCATION, self::SECTION_ACCESS];
	}

	/**
	 * Served, yet only to a request that names them: a request that names no sections goes without
	 * them. The section of access describes who else reaches an object, which a client that did not
	 * ask for it has no use for, and its assembly is not free.
	 *
	 * @return string[]
	 */
	public static function getOnDemandSections(): array
	{
		return [self::SECTION_ACCESS];
	}

	/**
	 * The sections of a request that names none: everything the portal serves but the sections served
	 * on demand.
	 *
	 * @return string[]
	 */
	public static function getDefaultSections(): array
	{
		return array_values(array_diff(self::getAvailableSections(), self::getOnDemandSections()));
	}

	/**
	 * Declared in the schema, not filled yet.
	 *
	 * @return string[]
	 */
	public static function getUnavailableSections(): array
	{
		return [self::SECTION_RELATIONS];
	}

	/**
	 * The sections whose assembly is expensive enough to bring a page down to
	 * {@see \Bitrix\Disk\Internal\Service\Sync\SyncLimits::getPageSizeMaxHeavy()}, whichever of them
	 * are asked for together. A section not served yet is harmless here: asking for it is refused
	 * before the size of a page is chosen.
	 *
	 * @return string[]
	 */
	public static function getHeavySections(): array
	{
		return [self::SECTION_LOCATION, self::SECTION_RELATIONS, self::SECTION_ACCESS];
	}

	/**
	 * @return string[]
	 */
	public static function getDeclaredSections(): array
	{
		return [...self::getAvailableSections(), ...self::getUnavailableSections()];
	}

	public static function createDefault(): self
	{
		return new self(self::getDefaultSections());
	}

	public static function create(mixed $value, ?string $dtoClass = null, ?Request $request = null): self
	{
		if (!is_array($value) || $value === [] || !array_is_list($value))
		{
			throw StrictRequest::refusal('sections', 'DISK_SYNC_REQUEST_SECTIONS_INVALID');
		}

		$requested = [];
		foreach ($value as $section)
		{
			if (!is_string($section))
			{
				throw StrictRequest::refusal('sections', 'DISK_SYNC_REQUEST_SECTIONS_INVALID');
			}

			self::assertServed($section);
			$requested[] = $section;
		}

		// The declared order, so the answer does not depend on the order the client listed them in.
		return new self(array_values(array_intersect(self::getAvailableSections(), $requested)));
	}

	/**
	 * @return string[]
	 */
	public function getList(): array
	{
		return $this->sections;
	}

	public function has(string $section): bool
	{
		return in_array($section, $this->sections, true);
	}

	public function hasHeavySection(): bool
	{
		return array_intersect(self::getHeavySections(), $this->sections) !== [];
	}

	private static function assertServed(string $section): void
	{
		if (in_array($section, self::getAvailableSections(), true))
		{
			return;
		}

		if (in_array($section, self::getUnavailableSections(), true))
		{
			throw StrictRequest::refusal(
				'sections',
				'DISK_SYNC_REQUEST_SECTION_UNAVAILABLE',
				['#SECTION#' => $section],
			);
		}

		throw StrictRequest::refusal('sections', 'DISK_SYNC_REQUEST_SECTION_UNKNOWN', ['#SECTION#' => $section]);
	}
}
