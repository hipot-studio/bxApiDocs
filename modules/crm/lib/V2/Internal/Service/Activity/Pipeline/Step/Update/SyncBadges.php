<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\Provider\ProviderManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivityType;

/**
 * Lets the provider of the activity put its badges on the owners and takes them off the owner the activity is being
 * moved away from: row 34 of the owner table STEP-UPDATE (crm_activity.php:939-943 and :945-961), row D8-BADGES of
 * the registry of intentional deviations.
 *
 * WHAT A BADGE IS. A mark the interface shows on a deal, a contact or a company because of one of its activities -
 * a missed call, an overlapping event, a task awaiting a reaction. Which mark to put on and whether to put one on
 * at all is entirely the provider's business, so the row hands it the state and the owners and asks nothing itself.
 *
 * THE OWNERS HANDED OVER ARE THE ONES THE ACTIVITY KEPT, AND THIS IS WHERE V2 DIFFERS FROM LEGACY. Legacy intends
 * the intersection of the two sets of bindings and writes `array_intersect` over arrays of arrays: every element of
 * both sides casts to the string "Array", so the answer is the whole previous set whenever the current one is not
 * empty. V2 intersects by the two values that identify an owner - the kind and the identifier - which is the
 * intersection legacy meant (item 6 of section 15 of LBS-1, confirmed 2026-07-28). The consequence is visible where
 * the sets are disjoint: legacy hands over the whole previous set, V2 hands over nothing - and an empty set makes
 * the entry point of legacy read the owners of the activity out of storage instead, which is the fallback it has
 * for a caller that names none. Where the set of bindings did not move at all, both hand over the previous set,
 * which is the current one as well.
 *
 * THE MOVE OF THE BINDINGS IS A BRANCH OF ITS OWN AND NOT A SECOND ROW. The caller may say that the activity is
 * being moved from one object to another - the map of the move of the operation context - and the badges of the
 * object it is leaving have to go with it. Legacy performs it right here, out of the same
 * option, and the effect is the same one - the badges of a change of bindings - so it belongs to this row. The other
 * reader of that option is the timeline of row 41, which records the move; the pair travels as it was given and
 * whatever is not an identifier of an item is passed over, exactly as legacy does.
 *
 * THE SERIALIZED FIELDS ARE READ OFF THE ROW AND NOT OFF THE RUN. Two of the implementations decide by them - the
 * tag of an overlapping event of a to-do item, the codes of the participants of an open line - and legacy reads
 * them off the row it re-read (:713). The run carries the ones the OPERATION named (item 6 of the traps of the
 * cluster after the write), which on an update naming no settings is nothing at all: asking the run would make
 * every such update look like a to-do item whose tag has been removed. So the state of the row is read once more,
 * through the boundary of the storage.
 *
 * @internal
 */
final class SyncBadges implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. Badges are
	 * sourced by the activity, so there is nothing to synchronize.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. The owners handed to the provider are the
	 * ones both states name, so there is nothing to hand over.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	/** The key of the pair of the move naming the object the activity is leaving. */
	private const MOVE_SOURCE = 'source';

	private const COLUMN_TYPE_ID = 'TYPE_ID';

	private const COLUMN_OWNER_ID = 'OWNER_ID';

	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	private const COLUMN_ASSOCIATED_ENTITY_ID = 'ASSOCIATED_ENTITY_ID';

	private readonly ActivityRepositoryInterface $repository;

	/**
	 * @param ActivityRepositoryInterface|null $repository the storage boundary of the activities, asked for the
	 *        serialized fields of the row; the one seam of the step.
	 */
	public function __construct(?ActivityRepositoryInterface $repository = null)
	{
		$this->repository = $repository ?? new ActivityRepository();
	}

	public function getName(): string
	{
		return 'SyncBadges';
	}

	/**
	 * Every update asks the provider. Whether there is a provider at all, whether it puts badges on anything and
	 * which owners are worth one are all questions of the entry point and of the provider itself.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		ProviderManager::syncBadgesOnActivityUpdate(
			$activityId,
			$this->legacyFieldsOf($context, $activityId),
			self::legacyBindingsOf(self::ownersTheActivityKept($context)),
		);

		$leftBehind = self::objectTheActivityIsLeaving($context);
		if ($leftBehind !== null)
		{
			ProviderManager::syncBadgesOnBindingsChange($activityId, [], self::legacyBindingsOfTheMove($leftBehind));
		}

		return $result;
	}

	/**
	 * An activity whose badges could not be brought into line is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The owners of both states, by the kind and the identifier of each. A set of bindings that did not move is
	 * handed over whole - which is what legacy does through the `else` of its ternary - and the intersection is
	 * computed only where row 18 says the set really moved.
	 *
	 * @return ActivityBinding[]
	 */
	private static function ownersTheActivityKept(PipelineContext $context): array
	{
		$previous = $context->getPreviousActivity()->getBindings() ?? [];
		if (!$context->getChanges()->isChanged(ActivityChangeSet::bindings))
		{
			return array_values($previous);
		}

		$current = [];
		foreach ($context->getActivity()->getBindings() ?? [] as $binding)
		{
			$current[$binding->getKey()] = true;
		}

		return array_values(array_filter(
			$previous,
			static fn(ActivityBinding $binding): bool => isset($current[$binding->getKey()]),
		));
	}

	/**
	 * The object the activity is being moved away from, or null where the caller named no move of the bindings.
	 */
	private static function objectTheActivityIsLeaving(PipelineContext $context): ?ItemIdentifier
	{
		$source = $context->getOperationContext()->getMoveBindingsMap()[self::MOVE_SOURCE] ?? null;

		return $source instanceof ItemIdentifier ? $source : null;
	}

	/**
	 * The re-read state in the legacy form the entry point resolves the provider from and the provider decides by.
	 *
	 * @return array<string, mixed>
	 */
	private function legacyFieldsOf(PipelineContext $context, int $activityId): array
	{
		$activity = $context->getActivity();
		$transit = $context->getTransit();
		$stored = $this->repository->getProviderPayload($activityId) ?? new ProviderPayload();

		return [
			'ID' => $activityId,
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'RESPONSIBLE_ID' => (int)$activity->getResponsibleId(),
			'PROVIDER_ID' => (string)$activity->getProviderId(),
			'PROVIDER_TYPE_ID' => (string)$activity->getProviderTypeId(),
			'TYPE_ID' => self::intOf($transit, self::COLUMN_TYPE_ID, CCrmActivityType::Provider),
			'OWNER_TYPE_ID' => self::intOf($transit, self::COLUMN_OWNER_TYPE_ID, 0),
			'OWNER_ID' => self::intOf($transit, self::COLUMN_OWNER_ID, 0),
			'ASSOCIATED_ENTITY_ID' => self::intOf($transit, self::COLUMN_ASSOCIATED_ENTITY_ID, 0),
			'SETTINGS' => self::arrayOf($stored, ProviderPayload::settings),
			'PROVIDER_PARAMS' => self::arrayOf($stored, ProviderPayload::providerParams),
		];
	}

	/**
	 * A set of bindings in the legacy shape.
	 *
	 * @param ActivityBinding[] $bindings
	 * @return array<int, array<string, int>>
	 */
	private static function legacyBindingsOf(array $bindings): array
	{
		$legacy = [];
		foreach ($bindings as $binding)
		{
			$legacy[] = [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			];
		}

		return $legacy;
	}

	/**
	 * The object of the move as the single removed binding of the change.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function legacyBindingsOfTheMove(ItemIdentifier $source): array
	{
		return [
			[
				'OWNER_TYPE_ID' => $source->getEntityTypeId(),
				'OWNER_ID' => $source->getEntityId(),
			],
		];
	}

	private static function intOf(StorageFieldTransit $transit, string $column, int $default): int
	{
		return $transit->has($column) ? (int)$transit->get($column) : $default;
	}

	/**
	 * A serialized field of the row, or the empty set legacy arrives at for a column holding nothing.
	 *
	 * @return array<mixed>
	 */
	private static function arrayOf(ProviderPayload $payload, string $field): array
	{
		$value = $payload->has($field) ? $payload->get($field) : null;

		return is_array($value) ? $value : [];
	}
}
