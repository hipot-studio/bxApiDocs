<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\AssociatedEntityCapabilityInterface;
use Bitrix\Main\Result;

/**
 * Disposes of the row in another subsystem the activity was a projection of - a task of the module of tasks, a
 * comment of one, a conference, a generated document: row 13 of the owner table STEP-DELETE
 * (crm_activity.php:1458-1466), normative shape TPL-07.
 *
 * THE GATE IS THE ONE OF ROW 38 OF AN UPDATE, WORD FOR WORD, and legacy writes the two conditions identically: the
 * caller has not asked for the associated entity to be left alone, the activity has a provider with something to say
 * about a projection, and the activity really names one. The capability is what "the provider has something to say"
 * means in V2 - the base class of the legacy providers defines the method as a no-op, so `$provider !== null` of
 * legacy and the capability of V2 answer the same for every provider standing behind the adapter.
 *
 * THE PROVIDER IS TOLD WHAT THE ACTIVITY WAS, and that is the difference from row 38 of an update: there is no set of
 * changes to hand over, and a provider decides by the fields of the activity whether the projection goes at all - the
 * one of generated documents removes the document only while the recycle bin is being emptied
 * ({@see AssociatedEntityCapabilityInterface::deleteAssociatedEntity()}). The state handed over is the model of the
 * run, which is the state row 2 read, with the title of the provider in its subject where row 5 put one.
 *
 * THE DEMAND TO LEAVE THE TASKS ALONE REACHES THE PROVIDER THROUGH THE CONTEXT. Legacy passes its whole option array
 * down here and the providers of tasks read `SKIP_TASKS` out of it; the context of the operation carries that demand
 * and the wrapper of a legacy provider projects it back into the array it hands over, so no step has to.
 *
 * THE HOOK CANNOT REFUSE. Legacy declared it returning a result and dropped that result at the call site, so the
 * activity is deleted whatever the projection makes of the news.
 *
 * THE PROVIDER IS THE ONE ROW 2 RESOLVED. A delete resolves it once, from the row it loaded, and every step below
 * asks {@see PipelineContext::getProvider()} for it: a second resolution here would be a second answer to one
 * question.
 *
 * @internal
 */
final class ProviderDeleteAssociatedEntity implements PipelineStepInterface
{
	private const COLUMN_ASSOCIATED_ENTITY_ID = 'ASSOCIATED_ENTITY_ID';

	public function getName(): string
	{
		return 'ProviderDeleteAssociatedEntity';
	}

	/**
	 * The gate of legacy, in the order that asks the cheap questions first. See the note of the class.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->getOperationContext()->shouldSkipAssociatedEntity())
		{
			return false;
		}

		if (!$context->getProvider() instanceof AssociatedEntityCapabilityInterface)
		{
			return false;
		}

		return self::associatedEntityIdOf($context->getTransit()) > 0;
	}

	public function execute(PipelineContext $context): Result
	{
		$provider = $context->getProvider();
		if ($provider instanceof AssociatedEntityCapabilityInterface)
		{
			$provider->deleteAssociatedEntity(
				self::associatedEntityIdOf($context->getTransit()),
				$context->getActivity(),
				$context->getOperationContext(),
			);
		}

		return new Result();
	}

	/**
	 * An activity whose projection could not be disposed of is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The row of the other subsystem this activity projected, as the column of the loaded state carries it.
	 */
	private static function associatedEntityIdOf(StorageFieldTransit $transit): int
	{
		return $transit->has(self::COLUMN_ASSOCIATED_ENTITY_ID)
			? (int)$transit->get(self::COLUMN_ASSOCIATED_ENTITY_ID)
			: 0
		;
	}
}
