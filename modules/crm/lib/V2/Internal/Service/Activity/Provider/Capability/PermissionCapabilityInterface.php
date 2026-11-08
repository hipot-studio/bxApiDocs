<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Access\Activity\AccessDecision;
use Bitrix\Crm\V2\Internal\Access\Activity\Operation;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Main\Error;

/**
 * The say the provider of an activity has in a decision about access to it.
 *
 * THREE OUTCOMES, NOT TWO. A provider may allow, may forbid, and may leave the question to the core, and
 * the third answer is what makes the rules of the core reachable at all ({@see AccessDecision}). Legacy
 * held the same three states in diverging shapes - two of its hooks returned a boolean and two returned
 * null for "the core decides" - and the third state was easy to lose on the way.
 *
 * DECLARING THE CAPABILITY IS ITSELF AN ANSWER. A provider with no rule of its own does not implement this
 * interface, and its activities are decided by the core alone. A legacy provider that overrides no read or
 * update hook of its own therefore says nothing either: the default of `Provider\Base` does return a
 * boolean, but that boolean is the core check restated, not an opinion - taking it for one would leave the
 * check over the bindings of an activity forever unreached.
 *
 * A REFUSAL OUTWEIGHS THE ADMINISTRATOR. The verdict is asked before the administrator bypass, so a
 * provider that forbids forbids everybody: a document activity refuses updating and postponing to portal
 * administrators too, and legacy arrived at the same answer by applying the hook on top of the core check.
 *
 * THE ONE SUBJECT IS A USER, and the operation is named ({@see Operation}). Completing and postponing are
 * asked about by name rather than as an update, because a provider may well answer differently: completing
 * a task delegates to the module of tasks, and postponing a document is refused outright.
 *
 * @internal
 */
interface PermissionCapabilityInterface
{
	public function decide(int $userId, Operation $operation, Activity $activity): AccessDecision;

	/**
	 * Why completing was refused, or null when the provider named no reason.
	 *
	 * It is a reason to show, not part of the verdict: whoever was refused a completion asks for it after
	 * {@see self::decide()} has answered. In legacy the value lives in one static of `Provider\Base` shared
	 * by every provider and never reset, so an implementation over a legacy provider reads that static and
	 * leaves it where it is.
	 */
	public function getCompletionDeniedError(): ?Error;
}
