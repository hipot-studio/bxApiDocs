<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

/**
 * A step whose throwable belongs to the caller of the operation: the engine logs it and hands it on instead of
 * carrying the run past it.
 *
 * WHY ANY STEP NEEDS THIS. Legacy catches nothing at all, so an exception of a subscriber of one of its events
 * leaves `CCrmActivity::Add()` and interrupts the operation in the middle - the effects above the publication stay
 * in place, the ones below never run, and the caller deals with the exception. The engine of V2 answers for a
 * throwable of a step the way it answers for a failure of it (ALG-01), which turns such an interruption into a
 * successful answer: the subscriber that refused by throwing is told nothing, and the caller learns nothing. For
 * the steps that publish the events of the domain that difference is not acceptable - a third-party handler
 * throwing is how a subscriber of the module refuses an operation today - so those steps declare themselves here.
 *
 * WHAT IT IS NOT. It is not a second form of "aborting on failure": a step declaring itself aborting answers with
 * the errors of its result and the operation ends as a refusal, which is a different outcome from a throwable
 * travelling out of the entry point. The two are independent - a step may be both, one, or neither - and the
 * contract of the error results is untouched by this one.
 *
 * WHY IT IS A MARKER AND NOT A METHOD OF EVERY STEP. Over a hundred steps declare a pipeline of an activity and
 * exactly the publications of the events belong here; a method with a default would have to be answered by all of
 * them, and the answer of a step nobody thought about would be a rule of the engine rather than a statement of the
 * step. The steps that do not implement this interface keep the behaviour of ALG-01 - logged and swallowed - which
 * is a registered deviation from legacy (row STEP-THROWABLE-SWALLOW of the registry of the ADR).
 *
 * @internal
 */
interface ThrowableTransparentStepInterface extends PipelineStepInterface
{
}
