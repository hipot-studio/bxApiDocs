<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormWriteContext;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSystemImmutableException;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository;
use Bitrix\Main\Result;

/**
 * Answers whether a CRM form would be accepted by a write, without performing one.
 *
 * A form is fit when its own configuration, sent back to a write, would be taken. So the check is
 * a dry run of that write and not a second set of rules: the same WebFormValidator the write
 * scenarios use answers here, over the same change set. The leniencies the rules make towards a
 * value a form already holds are part of that promise rather than a hole in it - the verdict says
 * "a write would take this", not "the form is beyond reproach".
 *
 * Two questions are answered by one contract. A form named by its identifier is checked as it is
 * stored: the stored form is turned into the set of changes it would send back. A configuration
 * sent along is checked instead, on top of the named form when there is one and on nothing when
 * there is not, which is how a creation that has not happened yet is checked.
 *
 * A stored form is read and sent back as a change ({@see WebFormWriteContext::Change}): it is
 * already there, so the write it would be taken by is the one that changes it. Read as a creation,
 * the check would carry the activity of the form - a value no write of a stored form ever sends -
 * and would answer about a document the client could not submit.
 *
 * Nothing is written and no version moves. The right asked for is the right to change a form: the
 * check is a dry run of a write, and a user who may not write is not shown the make-up of the
 * contract they may not use. A form that is not there and a form the reader may not have are
 * answered alike.
 *
 * Violations come back in the result rather than as an exception: they are the answer of this
 * scenario, not a failure of it. A refusal - no right, no form, a system form - is raised: a dry
 * run of a write cannot answer with a verdict where the write itself refuses.
 *
 * @internal
 */
final class FormChecker
{
	private readonly \Closure $permissionChecker;
	private readonly \Closure $formLoader;
	private readonly WebFormValidator $validator;

	/**
	 * @param \Closure(int): bool $permissionChecker the right to change CRM forms, given rather than
	 *        taken: this scenario is assembled by the caller that knows on whose behalf it runs
	 * @param null|\Closure(int, WebFormSelect): ?WebForm $formLoader
	 */
	public function __construct(
		\Closure $permissionChecker,
		?\Closure $formLoader = null,
		?WebFormValidator $validator = null,
	)
	{
		$this->permissionChecker = $permissionChecker;
		$this->formLoader = $formLoader ?? static fn (int $id, WebFormSelect $select): ?WebForm =>
			(new FormRepository())->getById($id, $select)
		;
		$this->validator = $validator ?? new WebFormValidator();
	}

	/**
	 * Checks the named form, the given configuration, or the configuration on top of the form.
	 *
	 * @return Result unsuccessful when the checked input carries violations; every error carries
	 *         the input path as its code
	 * @throws FormAccessDeniedException the user may not change CRM forms
	 * @throws FormNotFoundException there is no such form
	 * @throws FormSystemImmutableException the form is a system one and can not be changed
	 */
	public function check(int $userId, ?int $formId = null, ?WebFormChangeSet $changeSet = null): Result
	{
		$this->assertCanEdit($userId);

		$storedForm = $formId === null ? null : $this->loadForm($formId, $changeSet);
		if ($storedForm?->system === true)
		{
			throw new FormSystemImmutableException();
		}

		$changeSet ??= $storedForm === null
			? new WebFormChangeSet()
			: WebFormChangeSet::ofStoredForm($storedForm, WebFormWriteContext::Change)
		;

		return $this->validator->validate($changeSet, $storedForm, $userId);
	}

	/**
	 * @throws FormAccessDeniedException
	 */
	private function assertCanEdit(int $userId): void
	{
		if (!(bool)($this->permissionChecker)($userId))
		{
			throw new FormAccessDeniedException();
		}
	}

	/**
	 * @throws FormNotFoundException
	 */
	private function loadForm(int $formId, ?WebFormChangeSet $changeSet): WebForm
	{
		$form = ($this->formLoader)($formId, self::createFormSelect($changeSet));
		if (!$form instanceof WebForm)
		{
			throw new FormNotFoundException();
		}

		return $form;
	}

	/**
	 * A configuration sent along is weighed against the stored form by the very rules the write
	 * weighing it runs, so the form is read by the rule that write reads it with
	 * ({@see CurrentStateSelect}) and the dry run costs what the write costs.
	 *
	 * A form named alone is read whole: it is itself turned into the set of changes, and a path
	 * left out of the projection would read as a value the form does not hold.
	 */
	private static function createFormSelect(?WebFormChangeSet $changeSet): WebFormSelect
	{
		return $changeSet === null
			? self::createStoredFormSelect()
			: CurrentStateSelect::build($changeSet)
		;
	}

	/**
	 * What the stored form is read with: the paths the write contract carries, which is exactly
	 * what the form has to send back, plus the two the form is identified and told apart by.
	 *
	 * The list of paths is not repeated here. A second one would drift from the contract, and the
	 * check would then answer about a form read in part - silently, because a path left out reads
	 * as a value the form does not hold.
	 *
	 * The paths asked for are the paths the set is built of, so both take the same context.
	 */
	private static function createStoredFormSelect(): WebFormSelect
	{
		return WebFormSelect::fromPaths(array_merge(
			WebFormChangeSet::getWritablePaths(WebFormWriteContext::Change),
			[WebForm::FIELD_ID, WebForm::FIELD_SYSTEM],
		));
	}
}
