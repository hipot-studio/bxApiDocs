<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivity;
use CUserTypeManager;

/**
 * Writes the user fields of an activity: step 31 of the owner table of an add (crm_activity.php:413-425) and step 45
 * of the owner table of an update (:1186), checklist item B7.
 *
 * WHO THE FIELDS ARE WRITTEN AS IS THE WHOLE OF THIS STEP. The user field manager records a user against every
 * value it stores, and for a file that record is the owner of the file and the source of the rights to read it. A
 * letter is written as its author and everything else as its editor, and the rule of a letter is asked of
 * {@see EmailBranch::writesUserFieldsAsAuthor()} rather than spelled out here: that class is the one place a
 * legacy provider of email is named.
 *
 * ONE CLASS FOR BOTH PIPELINES AND NO MODE. On an update legacy has no branch for a letter at all and writes the
 * fields as an editor a letter never has, which leaves the files of a letter owned by nobody; V2 asks the same
 * question in both operations (ADR registry row D13-UF-EMAIL), so the two rows of the owner tables really are one
 * step, and there is nothing for a mode to configure.
 *
 * THE VALUES COME OFF THE OPERATION AND NOT OFF THE ACTIVITY. Legacy hands the manager its whole field array, and
 * the keys the manager picks out of it are the user fields the caller passed in - values no column of an activity
 * holds and no typed model of V2 carries, so their carrier is the boundary the input arrives through
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::getUserFieldValues()}) rather than a
 * value of the run: their producer is the caller, and a run value produced by nobody would be a stub. The call is
 * made unconditionally for the same reason legacy makes it - this is the place of the write - and an operation that
 * named no user field writes nothing, exactly as legacy writes nothing for such a call.
 *
 * @internal
 */
final class SaveUserFields implements PipelineStepInterface
{
	/**
	 * User fields belong to a stored activity: there is nothing to write them against before the row exists.
	 * Legacy states the same condition inline (`is_int($ID) && $ID > 0`, :414).
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_USER_FIELDS_WITHOUT_ACTIVITY';

	private ?CUserTypeManager $userFieldManager;

	/**
	 * @param CUserTypeManager|null $userFieldManager the manager of the user fields of the portal; the one seam of
	 *        the step. The global instance by default, resolved when the step runs rather than when it is declared.
	 */
	public function __construct(?CUserTypeManager $userFieldManager = null)
	{
		$this->userFieldManager = $userFieldManager;
	}

	public function getName(): string
	{
		return 'SaveUserFields';
	}

	/**
	 * Every operation writes the user fields of its activity.
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

		$this->manager()->update(
			CCrmActivity::UF_ENTITY_TYPE,
			$activityId,
			$context->getOperationContext()->getUserFieldValues(),
			self::authorOf($context),
		);

		return new Result();
	}

	/**
	 * An activity whose user fields could not be written is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The user the values are recorded against: the author of a letter, the editor of anything else.
	 */
	private static function authorOf(PipelineContext $context): int
	{
		$activity = $context->getActivity();

		return EmailBranch::writesUserFieldsAsAuthor($context->getTransit())
			? (int)$activity->getAuthorId()
			: (int)$activity->getEditorId()
		;
	}

	private function manager(): CUserTypeManager
	{
		$this->userFieldManager ??= $GLOBALS['USER_FIELD_MANAGER'];

		return $this->userFieldManager;
	}
}
