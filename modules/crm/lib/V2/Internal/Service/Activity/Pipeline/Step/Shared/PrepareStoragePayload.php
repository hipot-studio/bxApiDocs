<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;

/**
 * Brings the values that travel to the storage as one blob into the shape it stores them in: step 4 of the owner
 * table of an add (crm_activity.php:108-125) and step 5 of the owner table of an update (:514-557).
 *
 * WHAT LEGACY DID HERE AND WHO DOES IT NOW. Legacy serialized three values at this point - the attached
 * elements, the settings and the params of the provider - and the serialization is not a step of a pipeline in
 * V2: the repository owns the form a column is written in, down to the one column it has to write with an
 * operator of its own. What is left is what the serialization was preceded by, and both halves of it are
 * observable outside the storage:
 * - the set of attached elements is deduplicated (`NormalizeStorageElementIDs`, :1580-1589), and the set the
 *   steps below hand around is the deduplicated one - the bridge of the live feed reads it off the model, and
 *   the caller of the facade gets it back;
 * - the params of the provider are an array or they are nothing (:2154-2155): a value of any other shape is
 *   replaced with an empty array rather than stored as it came.
 *
 * ONE CLASS FOR BOTH OPERATIONS, WITHOUT A MODE. The two owner tables name the same step and the two legacy
 * blocks differ in one thing only - an update treats a set of elements that is not an array as a set nobody
 * touched (:522-526) - and the typed model cannot express that shape at all: a set is either given as
 * {@see ActivityStorageElement} objects or not given. So there is nothing for the operation to decide here, and
 * a parameter naming it would configure nothing.
 *
 * THE REST OF THE BLOCK OF AN UPDATE BELONGS TO THE STORAGE BOUNDARY, and that is the second half of the same
 * answer. What :514-557 does beyond the two rules above is collect the columns stored as one blob each into the
 * bound parameters of one statement (:529-558, spent at :663-670): which values a write binds rather than inlines
 * is a property of the statement, and V2 has no statement here - the repository writes through the ORM, which
 * binds everything. The one rule of the shape that IS observable outside the write - the params of a provider
 * being an array or nothing - is applied by legacy at :2155, that is at the end of the check of the fields and
 * therefore after the provider has already seen them, on an add and on an update alike; keeping it in this step
 * puts it in the same place relative to every reader it has.
 *
 * @internal
 */
final class PrepareStoragePayload implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'PrepareStoragePayload';
	}

	/**
	 * Only where the operation says something about the values this step shapes.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getActivity()->getFiles() !== null
			|| $context->getPayload()->has(ProviderPayload::providerParams)
		;
	}

	public function execute(PipelineContext $context): Result
	{
		$this->deduplicateElements($context->getActivity());
		$this->shapeProviderParams($context->getPayload());

		return new Result();
	}

	/**
	 * Legacy interrupts nothing over the shape of these values, and neither does this step.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * One element is attached once. The first occurrence of it keeps its place, which is the order legacy
	 * leaves behind.
	 */
	private function deduplicateElements(Activity $activity): void
	{
		$elements = $activity->getFiles();
		if ($elements === null)
		{
			return;
		}

		$unique = [];
		foreach ($elements as $element)
		{
			$unique[$element->getElementId()] = $element;
		}

		if (count($unique) === count($elements))
		{
			// Nothing to shape, and a set written back would count as a set the caller touched.
			return;
		}

		$activity->setFiles(...array_values($unique));
	}

	/**
	 * The params of a provider are a set of values or they are empty; anything else is a value the storage
	 * would keep and nobody could read back.
	 */
	private function shapeProviderParams(ProviderPayload $payload): void
	{
		if (!$payload->has(ProviderPayload::providerParams))
		{
			return;
		}

		if (!is_array($payload->get(ProviderPayload::providerParams)))
		{
			$payload->set(ProviderPayload::providerParams, []);
		}
	}
}
