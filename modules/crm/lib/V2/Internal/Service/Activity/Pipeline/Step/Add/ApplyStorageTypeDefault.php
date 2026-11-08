<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;

/**
 * Says which storage the files of a new activity live in when the caller did not: step 2 of the owner table of
 * an add (crm_activity.php:85-88).
 *
 * WHICH DEFAULT, AND WHY NOT THE OTHER ONE. The core has two answers to this question and only one of them is
 * reachable on the path of an add: the one asked here (`StorageType::getDefaultTypeID()`, the disk of the
 * portal when it is converted, the WebDAV before that, plain files otherwise) and the one of
 * `CCrmActivity::GetDefaultStorageTypeID()` (:2029-2032), which reads a personal option of the user and is
 * never reached because this step has already filled the column by then. The reachable one is the one kept.
 *
 * THE COLUMN IS NEITHER A FIELD OF THE MODEL NOR A DEFAULT OF THE REPOSITORY. One storage serves all files of
 * one activity, so the type of it belongs to the row and not to the elements; and the repository does not
 * invent it, because the answer depends on which modules the portal has - a question the storage layer has no
 * business asking. The step that saves the elements and the layer that deletes them read it out of the run.
 *
 * @internal
 */
final class ApplyStorageTypeDefault implements PipelineStepInterface
{
	private const COLUMN_STORAGE_TYPE_ID = 'STORAGE_TYPE_ID';

	public function getName(): string
	{
		return 'ApplyStorageTypeDefault';
	}

	/**
	 * Only where the operation carries no storage at all. Legacy asks it with `isset()`, so a column carried
	 * as null is a column nobody assigned.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$transit = $context->getTransit();

		return !$transit->has(self::COLUMN_STORAGE_TYPE_ID)
			|| $transit->get(self::COLUMN_STORAGE_TYPE_ID) === null
		;
	}

	public function execute(PipelineContext $context): Result
	{
		$context->getTransit()->set(self::COLUMN_STORAGE_TYPE_ID, StorageType::getDefaultTypeID());

		return new Result();
	}

	/**
	 * Legacy interrupts nothing over the storage of the files, and the column is nullable.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
