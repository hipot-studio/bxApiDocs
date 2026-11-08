<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;
use CCrmActivity;
use CCrmEntityHelper;

/**
 * Drops what the legacy reader of an activity remembers about it within this hit: row 15 of the owner table of an
 * update (crm_activity.php:700).
 *
 * WHAT THE CACHE IS. `CCrmActivity::GetByID()` keeps the rows it has read in a static of the process (:2509-2525),
 * so everything reading an activity through legacy after this update - the effects below, the providers, the
 * timeline, whatever runs later in the same hit - would go on seeing the row as it was before the write. The
 * pipeline of V2 reads through its own storage boundary, which remembers nothing; this step exists for the readers
 * that are still legacy, and it is the reason the re-read of row 17 is a query and not a memory.
 *
 * IT DROPS THE WHOLE BUCKET AND NOT THIS ONE ACTIVITY, because that is what the legacy helper does: it is given a
 * key and forgets the entire cache of the name (entity_helper.php:55-61). Reproduced as it is - narrowing it to
 * one activity would leave rows of other activities in a cache legacy has been clearing since it was written, and
 * an update is not the place to find out who was relying on that.
 *
 * NO SEAM, AND THAT IS THE POINT. The cache belongs to the legacy reader, so there is nothing here to abstract:
 * the boundary of V2 has no cache to drop, and a wrapper over one static call would be a second name for it. The
 * same reasoning as the queue of reminders of row 7, which asks its own singleton directly.
 *
 * @internal
 */
final class InvalidateItemCache implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'InvalidateItemCache';
	}

	/**
	 * Every update drops it. Legacy has no path around this, and it does it whatever came of the write - the row
	 * being remembered is stale either way.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		CCrmEntityHelper::RemoveCached(CCrmActivity::CACHE_NAME, $context->getActivityId());

		return new Result();
	}

	/**
	 * Legacy interrupts nothing here, and there is nothing to fail: forgetting is unconditional.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
