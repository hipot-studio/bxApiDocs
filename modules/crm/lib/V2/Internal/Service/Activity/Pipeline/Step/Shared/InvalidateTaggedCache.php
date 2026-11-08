<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;
use CCacheManager;

/**
 * Drops the managed cache of everything that rendered the activity: row 48 of the owner table STEP-UPDATE
 * (crm_activity.php:1213-1216) and row 22 of the owner table STEP-DELETE (:1523-1526), items D12 and E10.
 *
 * WHAT THE TAG IS. A component rendering an activity registers the tag `CRM_ACTIVITY_<id>` with the manager of the
 * managed cache (`livefeed.php:503`), which is how the cached HTML of a page is tied to the row it was built from.
 * Clearing the tag is what makes such a page rebuild. Nothing of V2 registers tags: this row exists for the
 * components that are still legacy, and until one of them is rewritten the effect is theirs alone.
 *
 * THE GATE IS THE CONSTANT AND NOTHING ELSE. Legacy asks `defined('BX_COMP_MANAGED_CACHE')`, which the front
 * controller of a portal defines and a runner of the console does not, so the row is inapplicable outside a hit of
 * the portal - a to-do completed by an agent clears no tag in legacy either, and reproducing that is the point.
 * The question is asked of the constant rather than of the manager: a manager exists in both cases and clearing a
 * tag while the caching is off would be a write legacy never makes.
 *
 * ONE CLASS FOR THE TWO PIPELINES THAT HAVE IT, AND NO MODE. An update and a delete clear the same tag of the same
 * activity under the same gate, and there is nothing left for an operation to configure - which is the whole test
 * of a shared step. An add has no such row at all (item C12: it registers nothing to clear, because the activity
 * did not exist when the pages were built).
 *
 * THE LAST ROW OF ITS PIPELINE, AND THAT IS THE ORDER OF LEGACY (row D12): the tag is cleared after both events of
 * the tail, so a subscriber that renders something of its own during the event does not get its own render cached
 * away a line later.
 *
 * @internal
 */
final class InvalidateTaggedCache implements PipelineStepInterface
{
	/** The managed cache of a hit of the portal is on. Legacy asks for the constant by this very name. */
	private const MANAGED_CACHE_CONSTANT = 'BX_COMP_MANAGED_CACHE';

	private const TAG_PREFIX = 'CRM_ACTIVITY_';

	private ?CCacheManager $cacheManager;

	/**
	 * @param CCacheManager|null $cacheManager the manager of the managed cache of the portal; the one seam of the
	 *        step. The global instance by default, resolved when the step runs rather than when it is declared.
	 */
	public function __construct(?CCacheManager $cacheManager = null)
	{
		$this->cacheManager = $cacheManager;
	}

	public function getName(): string
	{
		return 'InvalidateTaggedCache';
	}

	/**
	 * Only within a hit that keeps a managed cache. See the note of the class on why the constant is the gate.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return defined(self::MANAGED_CACHE_CONSTANT);
	}

	public function execute(PipelineContext $context): Result
	{
		$this->manager()->ClearByTag(self::TAG_PREFIX . $context->getActivityId());

		return new Result();
	}

	/**
	 * An activity whose pages could not be dropped is written all the same: legacy checks nothing here, and this
	 * is the last thing it does before answering the caller.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private function manager(): CCacheManager
	{
		$this->cacheManager ??= $GLOBALS['CACHE_MANAGER'];

		return $this->cacheManager;
	}
}
