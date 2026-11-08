<?php

declare(strict_types=1);

namespace Bitrix\AI\Analytics;

use Bitrix\AI\Facade\Analytics;
use Bitrix\Main\Config\Option;
use Closure;
use Throwable;

/**
 * Decides whether a written module option or an enabled package feature flag is a feature
 * release, and remembers the flags already reported to analytics.
 *
 * The class knows nothing about consuming modules: both the watched module id and its list of
 * excluded option names come from the outside.
 */
final class FeatureReleaseTracker
{
	public const MARKER_MODULE_SUFFIX = '.release';

	/**
	 * Says that the memory of the module has not been filled yet, so nothing may be reported: an
	 * empty memory would report every long-enabled flag as a release on its first rewrite.
	 *
	 * Set by an updater that had to skip the seeding, and cleared by the seeding itself. The `~`
	 * keeps the key out of the option names the memory is keyed by - such a name cannot become a
	 * flag identifier anyway, the format guard refuses it.
	 */
	public const SEED_PENDING_KEY = '~seed_pending';

	private const MARKER_SENT = 'Y';
	private const MARKER_NOT_SENT = 'N';
	private const TRACKED_VALUE = 'Y';

	private readonly Closure $markerReader;
	private readonly Closure $markerWriter;
	private readonly Closure $moduleOptionsReader;
	private readonly Closure $eventSender;

	/**
	 * @param string $moduleId Module whose options are watched.
	 * @param string[] $excludedOptions Exact option names that are not feature flags.
	 */
	public function __construct(
		private readonly string $moduleId,
		private readonly array $excludedOptions = [],
		?Closure $markerReader = null,
		?Closure $markerWriter = null,
		?Closure $moduleOptionsReader = null,
		?Closure $eventSender = null,
	)
	{
		$this->markerReader = $markerReader
			?? static fn(string $markerModuleId, string $key): string
				=> (string)Option::get($markerModuleId, $key, self::MARKER_NOT_SENT);
		$this->markerWriter = $markerWriter
			// No siteId on purpose: a per-site marker would turn "once" into "once per site".
			?? static function (string $markerModuleId, string $key, string $value): void {
				Option::set($markerModuleId, $key, $value);
			};
		$this->moduleOptionsReader = $moduleOptionsReader
			// Empty siteId, not the default one: with the default site a per-site value overrides
			// the global one, and the seeding would read a scope the write handler never tracks.
			?? static fn(string $moduleId): array => Option::getForModule($moduleId, '');
		$this->eventSender = $eventSender
			?? static fn(string $flagIdentifier): bool => Analytics::sendFeatureReleaseEvent($flagIdentifier);
	}

	/**
	 * Handles a write of `'Y'` into an option of the watched module.
	 *
	 * The caller has already checked the module, the value and the site; here the option is
	 * matched against the exclusion list and against the memory of sent flags.
	 *
	 * @param string $name Option name as it came from the write event.
	 * @return void
	 */
	public function handleOptionWrite(string $name): void
	{
		// The package flag hook calls the policy too, so the guard belongs here and not only
		// in the option trigger closure.
		try
		{
			if (in_array($name, $this->excludedOptions, true))
			{
				return;
			}

			if ($this->isSeedPending())
			{
				return;
			}

			if ($this->isSent($name))
			{
				return;
			}

			$this->sendAndMark($name, Analytics::optionNameToFlagIdentifier($name));
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * Handles a release of a package feature flag.
	 *
	 * There is no selection here: declaring a flag through the release-tracked base class is
	 * itself the statement that the flag is a release, and the identifier is explicit.
	 *
	 * @param string $releaseId Feature identifier in lowerCamelCase, without the `flag_` prefix.
	 * @return void
	 */
	public function handleFlagRelease(string $releaseId): void
	{
		try
		{
			if ($this->isSeedPending())
			{
				return;
			}

			if ($this->isSent($releaseId))
			{
				return;
			}

			$this->sendAndMark($releaseId, $releaseId);
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * Marks every option of the watched module that currently holds `'Y'` globally as already
	 * reported, without sending anything.
	 *
	 * Runs once from the module updater. The exclusion list is deliberately not applied: as soon
	 * as a name leaves the list, an unmarked option would report the date of its next rewrite as
	 * the release date.
	 *
	 * Filling the memory also lifts the ban on reporting, see SEED_PENDING_KEY.
	 *
	 * @return void
	 */
	public function seedSentMarkers(): void
	{
		foreach (($this->moduleOptionsReader)($this->moduleId) as $name => $value)
		{
			if ($value !== self::TRACKED_VALUE)
			{
				continue;
			}

			$this->markSent((string)$name);
		}

		// Last, and by the seeding itself: whoever fills the memory lifts the ban on reporting, so
		// a later updater that seeds is correct without knowing about the ban at all.
		$this->clearSeedPending();
	}

	/**
	 * Reporting is banned while the memory of the module has not been filled: on such a portal the
	 * memory is empty, and the first rewrite of any long-enabled flag would look like a release.
	 */
	private function isSeedPending(): bool
	{
		return ($this->markerReader)($this->getMarkerModuleId(), self::SEED_PENDING_KEY) === self::MARKER_SENT;
	}

	private function clearSeedPending(): void
	{
		($this->markerWriter)($this->getMarkerModuleId(), self::SEED_PENDING_KEY, self::MARKER_NOT_SENT);
	}

	/**
	 * Reading the marker and writing it is left without a lock on purpose: a duplicate event with
	 * the same date costs less than a date lost to a failed lock (the trade-off is in the ADR).
	 */
	private function sendAndMark(string $markerKey, string $flagIdentifier): void
	{
		if (!($this->eventSender)($flagIdentifier))
		{
			return;
		}

		$this->markSent($markerKey);
	}

	private function isSent(string $key): bool
	{
		return ($this->markerReader)($this->getMarkerModuleId(), $key) === self::MARKER_SENT;
	}

	/**
	 * Renaming a watched option requires moving its marker in the very same change: the memory
	 * key is the option name, so without the move the metric gets a second release with the
	 * date of the refactoring.
	 */
	private function markSent(string $key): void
	{
		($this->markerWriter)($this->getMarkerModuleId(), $key, self::MARKER_SENT);
	}

	/**
	 * Markers live in a pseudo module id, so the module check in the write handler cuts recursion
	 * off by construction and the module option list never shows them.
	 */
	private function getMarkerModuleId(): string
	{
		return $this->moduleId . self::MARKER_MODULE_SUFFIX;
	}
}
