<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Pseudoactivity\WaitEntry;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\CompletionCapabilityInterface;
use Bitrix\Main\Result;

/**
 * Closes the waiting of the deals and the leads the new activity is bound to: step 36 of the owner table of an add
 * (crm_activity.php:461-464).
 *
 * WHAT A WAITING IS. A pseudo-activity of its own table, put on an owner to say that somebody is expected to come
 * back; a real activity appearing on that owner is what ends it. Legacy performs the effect on every add, and the
 * subsystem itself decides which of the bindings it is about - only the deals and the leads - so that rule is not
 * copied here.
 *
 * THE GATE IS THE PREDICATE OF THE PROVIDER, and it is the one thing about this step that is not the subsystem's
 * own: an activity that itself is a form of waiting must not end one ({@see
 * CompletionCapabilityInterface::checkForWaitingCompletion()}). A completed call or letter of an incoming direction
 * still ends it, which is why the predicate belongs to the provider rather than to the completion of the activity.
 * A provider stating no rule of its own is read by the default of the base class of the legacy providers - an
 * activity that is not complete ends a waiting (provider/base.php:399-402) - and so is an activity with no provider
 * at all, which the subsystem then refuses for itself: it resolves the provider through the legacy registry a
 * second time.
 *
 * THE ARRAY IS NARROW ON PURPOSE. The subsystem asks the provider again over what it is handed, so what travels is
 * what the four implementations of that predicate read - the provider, the completion and the direction - plus the
 * effective set of bindings as the parameter legacy passes it in. The identifier of the activity plays no part at
 * all: the waitings are rows of the owners, not of the activity, so there is nothing here to refuse over an
 * activity that has no row yet.
 *
 * @internal
 */
final class RegisterWaitEntry implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'RegisterWaitEntry';
	}

	/**
	 * The predicate of the provider. See the note of the class.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$provider = $context->getProvider();
		if (!$provider instanceof CompletionCapabilityInterface)
		{
			// The answer of the base class of the legacy providers (provider/base.php:399-402), which is the
			// default a provider stating no rule of its own is read by.
			return $context->getActivity()->isCompleted() !== true;
		}

		return $provider->checkForWaitingCompletion($context->getActivity());
	}

	public function execute(PipelineContext $context): Result
	{
		$activity = $context->getActivity();

		WaitEntry::processActivityCreation(
			self::legacyFieldsOf($activity),
			['BINDINGS' => self::legacyBindingsOf($activity)],
		);

		return new Result();
	}

	/**
	 * A waiting that could not be closed leaves the activity stored: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The activity in the legacy shape the subsystem re-asks the provider over.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(Activity $activity): array
	{
		$fields = [
			'PROVIDER_ID' => (string)$activity->getProviderId(),
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
		];

		if ($activity->getDirection() !== null)
		{
			$fields['DIRECTION'] = (int)ActivityFieldMap::toColumnValue('DIRECTION', $activity->getDirection());
		}

		return $fields;
	}

	/**
	 * The effective set of bindings in the legacy shape, whole: which owners a waiting may stand on is the rule of
	 * the subsystem, and a second copy of it here would be a second answer.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function legacyBindingsOf(Activity $activity): array
	{
		$bindings = [];
		foreach ($activity->getBindings() ?? [] as $binding)
		{
			$bindings[] = [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			];
		}

		return $bindings;
	}
}
