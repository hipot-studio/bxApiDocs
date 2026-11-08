<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormActivationLimitReachedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormModuleUnavailableException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormPhoneVerificationRequiredException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormRemovalConfirmationRequiredException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSettingsOverflowException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSideEffectException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSystemImmutableException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormValidationException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormVersionMismatchException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormWriteRestrictedException;
use Bitrix\Crm\V2\Internal\Integration\Bitrix24\WebFormWriteRestriction;
use Bitrix\Crm\V2\Internal\Integration\Landing\FormPage;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormDeleteRepository;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormWriteRepository;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use UnexpectedValueException;

/**
 * Creates, changes and deletes a CRM form. Mirror of FormReader on the writing side.
 *
 * Every scenario follows the same discipline: checks before the write, then the domain validation
 * of the input, then the write, then the side effects. The first error stops the scenario, and it
 * stops by raising: an operation that did not finish never answers with success.
 *
 * The scenarios are orchestrated here and carried out elsewhere: WebFormValidator answers for the
 * domain validation of the input, FormWriteRepository and FormDeleteRepository for the storage, and
 * the integration layers for the other modules. What belongs to this class is the order of the
 * steps, the reading of the refusals and their translation into the exceptions of the layer.
 *
 * There is no transaction. A scenario that raises after the form row is written leaves the form
 * created and partially filled; the raised error says the operation did not finish, not that
 * nothing changed. The write order of the sections is fixed by FormWriteRepository, so the reached
 * state is predictable.
 *
 * @internal
 */
final class FormWriter
{
	/** Operation names carried by FormSideEffectException. */
	public const OPERATION_BUILD_SCRIPTS = FormScriptBuilder::OPERATION;
	public const OPERATION_CREATE_LANDING = 'createLanding';
	public const OPERATION_DELETE_CASCADE = 'deleteCascade';
	public const OPERATION_DELETE_FORM = 'deleteForm';

	private const COPYRIGHT_REMOVED_PATH =
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_COPYRIGHT_REMOVED;

	private const LOGGER_ID = 'Default';

	private readonly \Closure $permissionChecker;
	private readonly \Closure $formLoader;
	private readonly ActiveFormLimit $activeFormLimit;
	private readonly \Closure $formCreator;
	private readonly \Closure $formUpdater;
	private readonly \Closure $removalClaimer;
	private readonly \Closure $scriptBuilder;
	private readonly WebFormValidator $validator;
	private readonly WebFormWriteRestriction $writeRestriction;
	private readonly FormPage $formPage;
	private readonly FormDeleteRepository $deleteRepository;
	private ?FormStorageRefusal $storageRefusal;

	/**
	 * @param null|\Closure(int): bool $permissionChecker
	 * @param null|\Closure(int, WebFormSelect): ?WebForm $formLoader
	 * @param null|ActiveFormLimit $activeFormLimit defaults to the limit of this portal, asked
	 *        through the restriction this writer was given
	 * @param null|\Closure(WebFormChangeSet): Result $formCreator
	 * @param null|\Closure(int, WebFormChangeSet, ?DateTime=): Result $formUpdater
	 * @param null|\Closure(int, ?DateTime, bool): bool $removalClaimer
	 * @param null|\Closure(int): bool $scriptBuilder
	 * @param null|FormDeleteRepository $deleteRepository defaults to a repository deleting the page
	 *        of the form through the same landing layer as the rest of the scenarios
	 * @param null|FormStorageRefusal $storageRefusal defaults to a translation writing the refusals
	 *        of the storage to the log of the module
	 */
	public function __construct(
		?\Closure $permissionChecker = null,
		?\Closure $formLoader = null,
		?ActiveFormLimit $activeFormLimit = null,
		?\Closure $formCreator = null,
		?\Closure $formUpdater = null,
		?\Closure $removalClaimer = null,
		?\Closure $scriptBuilder = null,
		?WebFormValidator $validator = null,
		?WebFormWriteRestriction $writeRestriction = null,
		?FormPage $formPage = null,
		?FormDeleteRepository $deleteRepository = null,
		?FormStorageRefusal $storageRefusal = null,
	)
	{
		$this->permissionChecker = $permissionChecker ?? static fn (int $userId): bool =>
			Container::getInstance()
				->getUserPermissions($userId)
				->webForm()
				->canEdit()
		;
		$this->formLoader = $formLoader ?? static fn (int $id, WebFormSelect $select): ?WebForm =>
			(new FormRepository())->getById($id, $select)
		;
		$this->formCreator = $formCreator ?? static fn (WebFormChangeSet $changeSet): Result =>
			(new FormWriteRepository())->add($changeSet)
		;
		$this->formUpdater = $formUpdater ?? static fn (
			int $formId,
			WebFormChangeSet $changeSet,
			?DateTime $expectedModifiedTime = null,
		): Result =>
			(new FormWriteRepository())->update($formId, $changeSet, $expectedModifiedTime)
		;
		$this->removalClaimer = $removalClaimer ?? static fn (
			int $formId,
			?DateTime $expectedModifiedTime,
			bool $requireInactive,
		): bool =>
			(new FormWriteRepository())->claimForRemoval($formId, $expectedModifiedTime, $requireInactive)
		;
		$this->scriptBuilder = $scriptBuilder ?? static fn (int $formId): bool =>
			(new FormScriptBuilder())->build($formId)
		;
		$this->validator = $validator ?? new WebFormValidator();
		$this->writeRestriction = $writeRestriction ?? new WebFormWriteRestriction();
		$this->activeFormLimit = $activeFormLimit ?? new ActiveFormLimit(restriction: $this->writeRestriction);
		$this->formPage = $formPage ?? new FormPage();
		$this->deleteRepository = $deleteRepository ?? new FormDeleteRepository($this->formPage);
		$this->storageRefusal = $storageRefusal;
	}

	/**
	 * Creates a CRM form out of the change set and returns its id.
	 *
	 * The form is created in the state it is meant to work in: a creation saying nothing about the
	 * activity makes a working form. A creation that ends up with a working form is weighed against
	 * the limit of the portal before anything is written, so a refusal of the limit leaves no form
	 * behind; a creation making a form that does not work is never counted.
	 *
	 * @throws FormAccessDeniedException the user may not change CRM forms
	 * @throws FormWriteRestrictedException the plan of the portal does not allow writing forms
	 * @throws FormActivationLimitReachedException the creation makes a working form while the portal
	 *         already keeps as many forms working as its plan allows
	 * @throws FormPhoneVerificationRequiredException the portal asks for a confirmed phone number
	 * @throws FormModuleUnavailableException the module publishing the page of the form is absent
	 * @throws FormValidationException the input is not valid, or the storage rejected it; every
	 *         violation is carried at once, the code of each error is the input path, and a form the
	 *         refusal left behind is named by {@see FormValidationException::getCreatedFormId()}
	 * @throws FormSettingsOverflowException the settings of the form no longer fit the storage
	 * @throws FormSideEffectException a side effect of the creation failed after the form was
	 *         written; the form stays created, there is no rollback, and the refusal names its id
	 */
	public function add(int $userId, WebFormChangeSet $changeSet): int
	{
		$this->assertCanWrite($userId);
		// Creation asks about the form id zero, the way the editor does.
		$this->assertPhoneVerified(0);
		// Every form of this contract is of the default type, and a default type form is published
		// as a page, so the module is required before anything is written.
		if (!$this->formPage->isAvailable())
		{
			throw new FormModuleUnavailableException(FormPage::MODULE_ID);
		}

		$changeSet = $this->applyCopyrightRestriction($changeSet);
		$this->validate($changeSet, null, $userId);

		if (self::createsWorkingForm($changeSet))
		{
			$this->assertLimitNotReached();
		}

		$result = ($this->formCreator)($changeSet);
		$formId = (int)($result->getData()[FormWriteRepository::DATA_FORM_ID] ?? 0);
		if (!$result->isSuccess())
		{
			// There is no transaction, so a section failing after the row leaves the form created.
			// The id travels with the refusal: the caller has to be able to find what already
			// exists.
			throw $this->storageRefusal()->toException($result, $formId);
		}

		$this->buildScripts($formId, createdFormId: $formId);
		$this->createLandingPage($formId, $changeSet);

		return $formId;
	}

	/**
	 * Applies the change set to an existing CRM form. What the change set does not carry is left
	 * as it is.
	 *
	 * Success is the absence of an exception. The page of the form is not touched: a change reaches
	 * the visitor through the rebuilt script the page loads. A change set carrying nothing writes
	 * nothing and rebuilds nothing - it is answered by the checks alone.
	 *
	 * @throws FormAccessDeniedException the user may not change CRM forms
	 * @throws FormWriteRestrictedException the plan of the portal does not allow writing forms
	 * @throws FormPhoneVerificationRequiredException the portal asks for a confirmed phone number
	 * @throws FormNotFoundException there is no such form
	 * @throws FormSystemImmutableException the form is a system one and can not be changed
	 * @throws FormVersionMismatchException the form was changed after the expected version was read
	 * @throws FormValidationException the input is not valid, or the storage rejected it
	 * @throws FormSettingsOverflowException the settings of the form no longer fit the storage
	 * @throws FormSideEffectException rebuilding the script of the form failed
	 */
	public function update(
		int $userId,
		int $formId,
		WebFormChangeSet $changeSet,
		?DateTime $expectedModifiedTime = null,
	): void
	{
		$this->assertCanWrite($userId);
		$this->assertPhoneVerified($formId);

		$form = $this->loadForm($formId, $changeSet, includeModifiedTime: true);
		if ($form->system === true)
		{
			throw new FormSystemImmutableException();
		}

		if ($changeSet->isEmpty())
		{
			if ($expectedModifiedTime !== null)
			{
				$this->updateForm($formId, $changeSet, $expectedModifiedTime);
			}

			return;
		}

		$changeSet = $this->applyCopyrightRestriction($changeSet);
		$this->validate($changeSet, $form, $userId);

		$this->updateForm($formId, $changeSet, $expectedModifiedTime);

		$this->buildScripts($formId);
	}

	/**
	 * Deletes a CRM form together with the data the legacy cascade removes.
	 *
	 * The cascade is reproduced literally by FormDeleteRepository: the agreements, the responsible
	 * queue, the dependency groups, the rotation option and the background image stay behind as
	 * orphans, exactly as they do today. The built script package is the one addition to it, because
	 * nothing but the form names that package. What is new besides is that the three reasons of a
	 * refusal are told apart instead of sharing one false.
	 *
	 * The plan of the portal is asked here as it is asked on a creation and on a change: legacy
	 * checks it in the access predicate every changing action of the editor goes through, removal
	 * included, so leaving it out would remove a form on a plan where the editor refuses to. The
	 * confirmed phone number is not asked: no path of the legacy removal asks for it.
	 *
	 * Both guards of the removal stand before the first destroying step, because the cascade is
	 * irreversible and a half-run one is not rolled back. Their own order follows the same rule:
	 * the expected version is the picture the whole request was formed against, so a form changed
	 * since is refused before anything is decided out of that stale picture - a client that learns
	 * about the working form first would ask its user to confirm a removal it has yet to be told to
	 * reread. A form that may not be removed at all is refused ahead of both: being a system form is
	 * a property of the form rather than an answer to what the request carries.
	 *
	 * Answered on the read alone the guards would say nothing about the row the cascade then runs
	 * on, so the removal is claimed before it: the same conditions travel as conditions of one
	 * statement over the row ({@see FormWriteRepository::claimForRemoval()}), and a form changed or
	 * turned on in between is not claimed and loses nothing. The read keeps its own guards - they
	 * answer the everyday refusals without spending a statement on a form the caller may not remove
	 * at all.
	 *
	 * Neither guard is demanded of a client that carries nothing: a request without the expected
	 * version removes the form without comparing versions, and a form that does not work is removed
	 * unconfirmed the way it always was.
	 *
	 * @param DateTime|null $expectedModifiedTime the version the caller expects the form to carry;
	 *        null removes the form whatever version it carries
	 * @param bool $confirmed the removal of a working form is meant
	 * @throws FormAccessDeniedException the user may not change CRM forms
	 * @throws FormWriteRestrictedException the plan of the portal does not allow writing forms
	 * @throws FormNotFoundException there is no such form, a repeated delete included
	 * @throws FormSystemImmutableException the form is a system one and can not be deleted
	 * @throws FormVersionMismatchException the form was changed after the expected version was read
	 * @throws FormRemovalConfirmationRequiredException the form is working and the removal is not
	 *         confirmed
	 * @throws FormSideEffectException the cascade or the removal of the form row failed; the form
	 *         may already have lost part of its data, there is no rollback
	 */
	public function delete(
		int $userId,
		int $formId,
		?DateTime $expectedModifiedTime = null,
		bool $confirmed = false,
	): void
	{
		$this->assertCanWrite($userId);

		$form = $this->loadRemovedForm($formId, $expectedModifiedTime, $confirmed);
		if ($form->system === true)
		{
			throw new FormSystemImmutableException();
		}

		self::assertRemovable($form, $expectedModifiedTime, $confirmed);
		$this->claimRemoval($formId, $expectedModifiedTime, $confirmed);

		if (!$this->deleteRepository->deleteCascade($formId)->isSuccess())
		{
			// The form row is still there, so the delete can be repeated - by a caller rereading
			// the form first, because the claim has spent the version it was made with.
			throw new FormSideEffectException(self::OPERATION_DELETE_CASCADE);
		}

		if (!$this->deleteRepository->deleteFormRow($formId)->isSuccess())
		{
			throw new FormSideEffectException(self::OPERATION_DELETE_FORM);
		}

		Form::cleanCacheByTag($formId);
	}

	/**
	 * The right every scenario here starts with: may this user change CRM forms at all.
	 *
	 * Published so that a caller can ask it before doing any work of its own on the input. Reading
	 * an input first would answer a user without the right with the make-up of a contract they may
	 * not use. The scenarios ask it again on their own: the order of a caller is not what the write
	 * layer stands on.
	 *
	 * @throws FormAccessDeniedException
	 */
	public function assertCanEdit(int $userId): void
	{
		if (!(bool)($this->permissionChecker)($userId))
		{
			throw new FormAccessDeniedException();
		}
	}

	private function assertCanWrite(int $userId): void
	{
		$this->assertCanEdit($userId);

		if (!$this->writeRestriction->isWriteAllowed())
		{
			throw new FormWriteRestrictedException();
		}
	}

	private function assertPhoneVerified(int $formId): void
	{
		if (!$this->writeRestriction->isPhoneVerified($formId))
		{
			throw new FormPhoneVerificationRequiredException();
		}
	}

	/**
	 * Tells whether the creation ends up with a form that works. The change set is read the way the
	 * write reads it: the activity it does not carry is filled in by the creation default of the
	 * storage, which is a working form ({@see FormWriteRepository}).
	 */
	private static function createsWorkingForm(WebFormChangeSet $changeSet): bool
	{
		return !$changeSet->has(WebForm::FIELD_ACTIVE) || (bool)$changeSet->get(WebForm::FIELD_ACTIVE);
	}

	/**
	 * The limit is asked about the way turning a stored form on asks about it
	 * ({@see FormActivator::setActive()}), because it is the same growth of the number of working
	 * forms - and it is asked of the same object, so what refuses a creation here and what refuses
	 * an activation there are one comparison rather than two.
	 *
	 * The window between the count and the write is accepted: there is no transaction under this
	 * path, so creations overlapping in it all read the count before any of them writes and all
	 * pass: the number of working forms ends up above the limit by one less than the number of such
	 * creations.
	 *
	 * @throws FormActivationLimitReachedException
	 */
	private function assertLimitNotReached(): void
	{
		if ($this->activeFormLimit->isReached())
		{
			throw new FormActivationLimitReachedException();
		}
	}

	/**
	 * The guards of the removal read out of one picture of the form, in the order they refuse in.
	 *
	 * @throws FormVersionMismatchException
	 * @throws FormRemovalConfirmationRequiredException
	 */
	private static function assertRemovable(
		WebForm $form,
		?DateTime $expectedModifiedTime,
		bool $confirmed,
	): void
	{
		if ($expectedModifiedTime !== null)
		{
			self::assertExpectedVersion($form, $expectedModifiedTime);
		}

		if ($form->active === true && !$confirmed)
		{
			throw new FormRemovalConfirmationRequiredException();
		}
	}

	/**
	 * Takes the form away from every other write before the cascade runs, and refuses the removal
	 * when the row no longer carries what the guards were answered out of.
	 *
	 * A row that was not claimed is read anew, and the guards answer about what it carries now - a
	 * form removed in between included, which is the missing form of a repeated removal. A row
	 * answering them again was changed once more between the claim and that read: the removal is
	 * refused all the same, because nothing destroying may run on a picture the claim did not hold,
	 * and it is refused by the guard that made the claim conditional at all.
	 *
	 * @throws FormNotFoundException
	 * @throws FormVersionMismatchException
	 * @throws FormRemovalConfirmationRequiredException
	 */
	private function claimRemoval(int $formId, ?DateTime $expectedModifiedTime, bool $confirmed): void
	{
		if (($this->removalClaimer)($formId, $expectedModifiedTime, !$confirmed))
		{
			return;
		}

		$current = $this->loadRemovedForm($formId, $expectedModifiedTime, $confirmed);
		self::assertRemovable($current, $expectedModifiedTime, $confirmed);

		if (!$confirmed)
		{
			throw new FormRemovalConfirmationRequiredException();
		}

		throw new FormVersionMismatchException(self::versionOf($current));
	}

	private function loadRemovedForm(
		int $formId,
		?DateTime $expectedModifiedTime,
		bool $confirmed,
	): WebForm
	{
		// Each value is read only while a guard of the removal needs it: the version when the caller
		// expects one, the activity while the removal is not confirmed.
		return $this->loadForm(
			$formId,
			includeModifiedTime: $expectedModifiedTime !== null,
			includeActivity: !$confirmed,
		);
	}

	/**
	 * Compares the version against the read the scenario has already made: the row was read a step
	 * above, nothing writes between the two, and a read of its own would answer the same value. The
	 * comparison moves no version - the claim standing after it may still refuse to run.
	 *
	 * @throws FormVersionMismatchException
	 */
	private static function assertExpectedVersion(WebForm $form, DateTime $expectedModifiedTime): void
	{
		$currentModifiedTime = self::versionOf($form);
		if ($currentModifiedTime->getTimestamp() !== $expectedModifiedTime->getTimestamp())
		{
			throw new FormVersionMismatchException($currentModifiedTime);
		}
	}

	private static function versionOf(WebForm $form): DateTime
	{
		$modifiedTime = $form->modifiedTime;
		if (!$modifiedTime instanceof DateTime)
		{
			throw new UnexpectedValueException(
				sprintf('CRM form `%d` was read without a version.', $form->id),
			);
		}

		return $modifiedTime;
	}

	private function loadForm(
		int $formId,
		?WebFormChangeSet $changeSet = null,
		bool $includeModifiedTime = false,
		bool $includeActivity = false,
	): WebForm
	{
		$form = ($this->formLoader)(
			$formId,
			CurrentStateSelect::build($changeSet, $includeModifiedTime, $includeActivity),
		);
		if (!$form instanceof WebForm)
		{
			throw new FormNotFoundException();
		}

		return $form;
	}

	private function updateForm(
		int $formId,
		WebFormChangeSet $changeSet,
		?DateTime $expectedModifiedTime,
	): void
	{
		$result = $expectedModifiedTime === null
			? ($this->formUpdater)($formId, $changeSet)
			: ($this->formUpdater)($formId, $changeSet, $expectedModifiedTime)
		;
		if (!$result->isSuccess())
		{
			throw $this->storageRefusal()->toException($result);
		}
	}

	/**
	 * Returns the change set with the copyright kept when the portal may not remove it. This is the
	 * plan of the portal talking, so it silently overrides the input instead of refusing it.
	 */
	private function applyCopyrightRestriction(WebFormChangeSet $changeSet): WebFormChangeSet
	{
		if (!$changeSet->has(self::COPYRIGHT_REMOVED_PATH))
		{
			return $changeSet;
		}

		if (!$changeSet->get(self::COPYRIGHT_REMOVED_PATH) || $this->writeRestriction->canRemoveCopyright())
		{
			return $changeSet;
		}

		return $changeSet->with(self::COPYRIGHT_REMOVED_PATH, false);
	}

	/**
	 * Raises every violation the validator found at once, so a caller learns about all of them
	 * instead of fixing one per request.
	 *
	 * @throws FormValidationException
	 */
	private function validate(WebFormChangeSet $changeSet, ?WebForm $storedForm, int $userId): void
	{
		$result = $this->validator->validate($changeSet, $storedForm, $userId);
		if (!$result->isSuccess())
		{
			throw new FormValidationException(...$result->getErrors());
		}
	}

	/**
	 * The translation a refused write is answered through, shared with the scenario switching the
	 * activity of a form ({@see FormActivator}) so that one refusal of the storage reaches the client
	 * as one answer. A writer built without one takes the log of the module, and takes it only when a
	 * write is actually refused.
	 */
	private function storageRefusal(): FormStorageRefusal
	{
		return $this->storageRefusal ??= new FormStorageRefusal(
			Container::getInstance()->getLogger(self::LOGGER_ID),
		);
	}

	/**
	 * Rebuilds the scripts of the form and resets its cache tag, in the order of the legacy save.
	 *
	 * @param int|null $createdFormId the id the refusal has to carry out: a creation is the one case
	 *        where the caller does not know it yet and the form is already there.
	 * @throws FormSideEffectException
	 */
	private function buildScripts(int $formId, ?int $createdFormId = null): void
	{
		if (!($this->scriptBuilder)($formId))
		{
			throw new FormSideEffectException(self::OPERATION_BUILD_SCRIPTS, $createdFormId);
		}
	}

	/**
	 * @throws FormSideEffectException
	 */
	private function createLandingPage(int $formId, WebFormChangeSet $changeSet): void
	{
		$formName = $changeSet->has(WebForm::FIELD_NAME)
			? (string)$changeSet->get(WebForm::FIELD_NAME)
			: null
		;

		try
		{
			$isCreated = $this->formPage->createPage($formId, $formName)->isSuccess();
		}
		catch (FormModuleUnavailableException)
		{
			// The module was there when the scenario checked and is gone now. Whatever the reason,
			// the form is already written, so the caller has to be told about it the way any other
			// failed side effect of a creation is told: repeating the call as it is would create a
			// second form.
			$isCreated = false;
		}

		if (!$isCreated)
		{
			// Only a creation gets this far, so the form is always one the caller has yet to learn.
			throw new FormSideEffectException(self::OPERATION_CREATE_LANDING, $formId);
		}
	}
}
