<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Search\SearchContentBuilderFactory;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * Rebuilds the text an activity is found by in the search: step 24 of the owner table of an add
 * (crm_activity.php:329-331) and step 31 of the owner table of an update (:872-874), checklist item C7.7.
 *
 * THE STEP HANDS OVER AN IDENTIFIER AND NOTHING ELSE, and that is the whole reason its place in the pipeline is
 * fixed. The builder re-reads the activity, its bindings and its communications out of storage itself, so it can
 * only see what has already been written: it has to stand below the write of the bindings and below the write of
 * the communications (section 13 item 3 of LBS-1). A step placed above them would index an activity bound to
 * nothing and reachable by no phone number, and nothing would fail - the row would simply be missing words.
 *
 * IT IS PERFORMED ON EVERY OPERATION, THE SETTING OF THE INDEXING INCLUDED. The portal-wide switch of the search
 * index of the CRM does not gate this: legacy writes the search content of an activity on every add regardless of
 * it (finding of the characterization of `[P1.T4]`), so asking about the setting here would silently stop
 * indexing activities on portals where the switch is off.
 *
 * THE SAME STEP SERVES BOTH PIPELINES AND HAS NO MODE. The gate is "always" on both sides and the effect is the
 * same single call over the identifier of the activity; there is nothing an add and an update could configure
 * differently (precedents: `SaveCommunications`, `PrepareStoragePayload`, `RegisterActivityStatisticEntry`).
 *
 * @internal
 */
final class BuildSearchContent implements PipelineStepInterface
{
	/**
	 * The content is keyed by the activity, so there is nothing to index before the row exists. Legacy cannot
	 * reach this state: it interrupts an add on a failed insert, and an update has an activity by definition.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_SEARCH_CONTENT_WITHOUT_ACTIVITY';

	public function getName(): string
	{
		return 'BuildSearchContent';
	}

	/**
	 * Every operation rebuilds the content. See the note of the class on why the setting of the indexing is not
	 * asked here.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		SearchContentBuilderFactory::create(CCrmOwnerType::Activity)->build($activityId);

		return new Result();
	}

	/**
	 * An activity whose search content could not be built is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
