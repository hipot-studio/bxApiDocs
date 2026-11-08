<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

use Bitrix\Main\Result;

/**
 * One named effect of an operation over an activity: the unit a pipeline is declared out of.
 *
 * ONE STEP IS ONE EFFECT OF LEGACY, AND IT IS NAMED AFTER IT. The names are normative - the owner tables of
 * the design spell them out, the failure log carries them and the tests comparing a declaration with its owner
 * table read them - so renaming a step changes a contract rather than a class.
 *
 * THE CONDITION BELONGS TO THE STEP, NOT TO THE ENGINE. Legacy guarded every one of its effects with an inline
 * condition over the values being written, and {@see self::isApplicable()} is where those conditions end up, one
 * step at a time. Neither a condition lifted into the engine nor a step deciding inside `execute()` whether it
 * has anything to do keeps the property this design rests on: the declaration of an operation reads as the list
 * of its effects together with their guards, in the order legacy performs them.
 *
 * WHAT A STEP RETURNS. A `Result`: errors of the domain go into it and only a programming error is thrown. Two
 * outcomes mean more than plain success and failure:
 * - a failure of a step that declares itself aborting ({@see self::isAbortingOnFailure()}) ends the operation,
 *   while a failure of any other step is logged and the operation carries on. That is legacy, which interrupts
 *   an add on its validation and on its insert and keeps going through everything else;
 * - a success carrying {@see self::STOP_WITH_SUCCESS} ends the operation successfully and skips every step below
 *   it. Two effects of the design need that - moving an activity to the recycle bin and completing one that is
 *   already complete - and neither of them is a failure.
 *
 * @internal
 */
interface PipelineStepInterface
{
	/**
	 * The key a step puts into the data of its result to end the operation successfully without the steps below
	 * it running. Boolean true is the signal; nothing else is.
	 */
	public const STOP_WITH_SUCCESS = 'stopWithSuccess';

	/**
	 * The name of the step as the owner table of its pipeline spells it, which is the short name of its class.
	 */
	public function getName(): string;

	/**
	 * Whether the step applies to the operation the context describes. It is asked once per run, right before
	 * the step would be executed, so a condition may read what the steps above have already written.
	 */
	public function isApplicable(PipelineContext $context): bool;

	/**
	 * Performs the effect. Whatever the steps below need out of it goes into the context.
	 */
	public function execute(PipelineContext $context): Result;

	/**
	 * Whether a failure of the step ends the whole operation. True only where legacy interrupts.
	 */
	public function isAbortingOnFailure(): bool;
}
