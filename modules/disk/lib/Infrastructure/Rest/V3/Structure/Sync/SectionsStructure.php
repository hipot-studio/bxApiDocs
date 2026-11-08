<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The sections of an object card a listing method is asked for.
 *
 * Two of the declared sections are not filled at this stage. They stay in the schema so that the
 * later stages can fill them without changing the base contract, and asking for one now is an
 * explicit refusal rather than a section that silently comes back empty: a client that got an empty
 * "access" section would have no way to tell "nothing is shared" from "the portal cannot answer
 * that yet". Which sections a portal serves is published by disk.sync.capabilities.get.
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
		return [self::SECTION_METADATA, self::SECTION_LOCATION];
	}

	/**
	 * Declared in the schema, not filled yet.
	 *
	 * @return string[]
	 */
	public static function getUnavailableSections(): array
	{
		return [self::SECTION_ACCESS, self::SECTION_RELATIONS];
	}

	/**
	 * @return string[]
	 */
	public static function getDeclaredSections(): array
	{
		return [...self::getAvailableSections(), ...self::getUnavailableSections()];
	}

	/**
	 * The sections of a request that names none: everything the portal serves.
	 */
	public static function createDefault(): self
	{
		return new self(self::getAvailableSections());
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
