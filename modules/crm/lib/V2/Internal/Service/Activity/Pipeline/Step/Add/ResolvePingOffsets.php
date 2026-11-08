<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PingCapabilityInterface;
use Bitrix\Main\Result;

/**
 * Decides which reminders a new activity is to have: step 19 of the owner table of an add
 * (crm_activity.php:303, the resolution itself at :8534-8555).
 *
 * THE STEP IS UNCONDITIONAL AND THE RULE OF THE CHOICE IS INSIDE IT. Legacy resolves the offsets on every add,
 * and the choice between what the caller named and what the provider offers is made inside the resolution.
 * Lifting that choice into the applicability of the step would be a regression rather than a simplification:
 * the step below that registers the queue of reminders would then be left without the offsets the caller did
 * name, since the carrier of the resolved ones is filled here and nowhere else (key constraint of `[P5.T3]`).
 *
 * OFFSETS OF THE CALLER WIN AND THE PROVIDER IS NOT ASKED AT ALL. That is the whole rule, and it is the reason
 * this is no place to enforce a policy on reminders: an add naming an empty list of offsets keeps that empty
 * list ({@see PingCapabilityInterface}). The defaults are asked for by the owner of the activity and by the
 * funnel of that owner, because the settings behind them are per funnel.
 *
 * THE RESOLVED OFFSETS ARE A VALUE OF THE RUN, NOT A FIELD OF THE ACTIVITY. The model keeps what the caller
 * named - which may be nothing at all - while the steps below need what was resolved, and legacy holds the
 * resolved ones in a local variable that never reaches the field array: the subscribers of the event of an
 * added activity see the offsets of the caller. Writing them back into the model would hand those subscribers
 * and the facade a value legacy never showed them, so they travel in
 * {@see PipelineContext::RUN_VALUE_PING_OFFSETS} instead.
 *
 * AN ACTIVITY WITHOUT A PROVIDER GETS AN EMPTY LIST. Legacy would have returned null from a method declared to
 * return an array, which is a TypeError rather than a behaviour; a run of V2 always carries a list.
 *
 * @internal
 */
final class ResolvePingOffsets implements PipelineStepInterface
{
	private const COLUMN_OWNER_ID = 'OWNER_ID';
	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	public function getName(): string
	{
		return 'ResolvePingOffsets';
	}

	/**
	 * Every add resolves its reminders. See the note of the class on why no part of the resolution is asked here.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$offsets = $context->getActivity()->getPingOffsets() ?? self::defaultsOfTheProvider($context);
		$context->setRunValue(PipelineContext::RUN_VALUE_PING_OFFSETS, $offsets);

		return new Result();
	}

	/**
	 * An activity whose reminders could not be resolved is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The offsets the provider of the activity offers for an owner of this kind, or none where there is no
	 * provider to ask.
	 *
	 * @return int[]
	 */
	private static function defaultsOfTheProvider(PipelineContext $context): array
	{
		$provider = $context->getProvider();
		if (!$provider instanceof PingCapabilityInterface)
		{
			return [];
		}

		$transit = $context->getTransit();
		$ownerTypeId = self::intOf($transit, self::COLUMN_OWNER_TYPE_ID);
		$ownerId = self::intOf($transit, self::COLUMN_OWNER_ID);

		return $provider->getDefaultPingOffsets($ownerTypeId, self::funnelOfTheOwner($ownerTypeId, $ownerId));
	}

	/**
	 * The funnel the owner of the activity is in, or null where it has none - which is what an owner of a kind
	 * without funnels answers and what an owner that is not there answers as well.
	 */
	private static function funnelOfTheOwner(int $ownerTypeId, int $ownerId): ?int
	{
		if ($ownerTypeId <= 0 || $ownerId <= 0)
		{
			return null;
		}

		return Container::getInstance()->getFactory($ownerTypeId)?->getItemCategoryId($ownerId);
	}

	private static function intOf(StorageFieldTransit $transit, string $column): int
	{
		return $transit->has($column) ? (int)$transit->get($column) : 0;
	}
}
