<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\Config;

class ComposedSection
{
	/** @var array<string, true> */
	private array $includeSet;
	/** @var array<string, true> */
	private array $excludeSet;

	/**
	 * @param string[] $include Sections that must be present in base sections for this composed section to match.
	 * @param string[] $exclude Sections that must NOT be present in base sections. Useful for excluding subsets
	 *                          from a broad section (e.g., excluding copilot/tasks from the default section).
	 */
	public function __construct(
		array $include = [],
		array $exclude = [],
	)
	{
		$this->includeSet = array_fill_keys($include, true);
		$this->excludeSet = array_fill_keys($exclude, true);
	}

	public function matchesSections(array $baseSections): bool
	{
		$baseSectionSet = array_fill_keys($baseSections, true);

		$included = array_intersect_key($baseSectionSet, $this->includeSet) !== [];
		$excluded = array_intersect_key($baseSectionSet, $this->excludeSet) !== [];

		return $included && !$excluded;
	}

	public function excludesSections(array $baseSections): bool
	{
		$baseSectionSet = array_fill_keys($baseSections, true);

		$included = array_intersect_key($baseSectionSet, $this->includeSet) !== [];
		$excluded = array_intersect_key($baseSectionSet, $this->excludeSet) !== [];

		return $included && $excluded;
	}
}
