<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Main\Result;

/**
 * What a provider has to say about completing an activity of its kind, and about moving one in time.
 *
 * THE DEFAULTS BELONG TO WHOEVER ASKS, not to this interface. A provider that does not implement it is
 * completable, is waited for as long as it is not completed, and is not completed by merely being looked at -
 * those are the answers of the caller in the absence of a capability, which is why none of them appears here as
 * a method body. Legacy had them as defaults on a base class, and there a provider with nothing to say was
 * indistinguishable from one that had chosen the same answer.
 *
 * COMPLETABILITY IS ABOUT AUTOMATIC COMPLETION. Saying no takes activities of this kind out of the completion
 * that follows the stage of their owner - the four messengers, a message of the live feed and the two kinds of
 * document being signed all say no - and it is not a right: a refusal aimed at one user belongs to
 * {@see PermissionCapabilityInterface}.
 *
 * POSTPONING IS NOT AN OPERATION OF THE SERVICE. It stays a capability because the existing entry point calls
 * it, and its own limit is carried over untouched: a provider shifts the fields it owns, and a deadline is not
 * one of them. Legacy dropped any deadline handed to an update before the provider ran, so the deadline of a
 * postponed activity moved only because the check of the fields derived it again from the start time that had
 * already moved. A provider without that derivation could not move a deadline at all, and cannot here either.
 *
 * Normative shape: TPL-04. The legacy methods and who overrides them: LBS-2 sections 2.4, 2.10 and 4.7,
 * checklist items C8 and C11.
 *
 * @internal
 */
interface CompletionCapabilityInterface
{
	/**
	 * Whether activities of this kind may be completed at all. Absent capability means they may.
	 */
	public function isCompletable(): bool;

	/**
	 * Whether an activity still counts as awaited. Absent capability means it does while it is not completed;
	 * the providers of calls, of mail and of open lines also keep waiting for an incoming one that is.
	 */
	public function checkForWaitingCompletion(Activity $activity): bool;

	/**
	 * Whether reading an activity of this kind counts as completing it. A predicate and nothing more: the
	 * completion an existing reader of the to-do list performs on rendering is that reader's own doing, and no
	 * new reader inherits it. Absent capability means no.
	 */
	public function canCompleteOnView(): bool;

	/**
	 * Moves an activity of this kind forward in time by the given number of seconds.
	 *
	 * THE SHIFT IS WRITTEN ONTO THE GIVEN ACTIVITY. Mutating it is the whole channel of the move, the same
	 * device the validation of a provider uses in place of the by-reference contract of legacy, and the
	 * setters the model needs for it exist. The deadline is not among the fields a provider may shift this
	 * way - see the note on postponing above.
	 *
	 * THE SET OF CHANGES RECORDS THE OUTCOME, it does not carry the intent. It is passed so that a provider
	 * that has moved a field can say what its two values were; nothing put into it moves anything, and a
	 * shift expressed only there is a shift that never happened. The result likewise reports success and
	 * carries no fields: a failing one refuses the postponement.
	 */
	public function tryPostpone(int $offsetSeconds, Activity $activity, ActivityChangeSet $changes): Result;
}
