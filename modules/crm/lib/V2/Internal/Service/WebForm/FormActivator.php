<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormActivationLimitReachedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSideEffectException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSystemImmutableException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormValidationException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormVersionMismatchException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormWriteRestrictedException;
use Bitrix\Crm\V2\Internal\Integration\Bitrix24\WebFormWriteRestriction;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormWriteRepository;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use UnexpectedValueException;

/**
 * Turns a CRM form on and off. A scenario of its own beside {@see FormWriter}, the way the write of
 * the embedding settings is: the activity of a form is not part of the change contract, so it is
 * changed by a scenario that owns the operation rather than by a flag of the write.
 *
 * The order of the steps repeats the discipline of the neighbouring scenarios: the right to change
 * CRM forms, then what the plan of the portal allows, then the existence and the admissibility of
 * the form. A confirmed phone number is not asked for - no existing path of turning a form on asks
 * for it, and neither does the removal of a form.
 *
 * A call that changes nothing writes nothing. A form already in the asked-for state is answered with
 * the state and the version it carries: no row is written, no version moves, the limit is not asked
 * about and the package is not rebuilt. This is what keeps a repeated call harmless on a portal that
 * has run out of its limit - the form being turned on is never counted against itself, because a
 * form that is already working is never counted at all. A caller guarding such a call with a version
 * is still answered about it: the version read here is compared with the expected one, so a switch
 * that changes nothing never reports success over a form that has moved on since it was read.
 *
 * Fitness is asked of {@see FormChecker} in the mode of a stored form, which is the very call
 * crm.form.validate answers with: the two verdicts are one verdict by construction rather than two
 * rules kept in step by hand. Turning a form off asks neither about fitness nor about the limit:
 * neither the number of working forms nor what visitors are shown grows by it.
 *
 * There is no transaction. A rebuild of the package failing after the row is written is reported as
 * a failed side effect rather than as success: the state is already changed while the visitors of
 * the pages the form is placed on keep receiving the previous package.
 *
 * The answer carries the state of the form and the version acting after the call, and never the
 * author of the change: the update event of the table takes that column out of a write turning a
 * form off ({@see \Bitrix\Crm\WebForm\Internals\FormTable::onBeforeUpdate()}), so the row keeps the
 * author of the last time the form was turned on, and answering with it would name a user who did
 * not make this change.
 *
 * @internal
 */
final class FormActivator
{
	private readonly \Closure $permissionChecker;
	private readonly \Closure $formLoader;
	private readonly \Closure $formChecker;
	private readonly ActiveFormLimit $activeFormLimit;
	private readonly \Closure $activeStateWriter;
	private readonly \Closure $scriptBuilder;
	private readonly WebFormWriteRestriction $writeRestriction;
	private readonly FormStorageRefusal $storageRefusal;

	/**
	 * @param \Closure(int): bool $permissionChecker the right to change CRM forms, given rather than
	 *        taken: this scenario is assembled by the caller that knows on whose behalf it runs
	 * @param \Closure(int, int): Result $formChecker the verdict crm.form.validate answers with,
	 *        given by the same caller so that both calls run one check over one stored form
	 * @param FormStorageRefusal $storageRefusal the translation a refused write is answered through,
	 *        shared with {@see FormWriter}
	 * @param null|\Closure(int, WebFormSelect): ?WebForm $formLoader
	 * @param null|ActiveFormLimit $activeFormLimit defaults to the limit of this portal, asked
	 *        through the restriction this scenario was given
	 * @param null|\Closure(int, bool, int, ?DateTime): Result $activeStateWriter the form, the state
	 *        asked for, the author of the change and the version the form has to still carry, which
	 *        the storage compares in the statement that writes
	 * @param null|\Closure(int): bool $scriptBuilder
	 */
	public function __construct(
		\Closure $permissionChecker,
		\Closure $formChecker,
		FormStorageRefusal $storageRefusal,
		?\Closure $formLoader = null,
		?ActiveFormLimit $activeFormLimit = null,
		?\Closure $activeStateWriter = null,
		?\Closure $scriptBuilder = null,
		?WebFormWriteRestriction $writeRestriction = null,
	)
	{
		$this->permissionChecker = $permissionChecker;
		$this->formChecker = $formChecker;
		$this->storageRefusal = $storageRefusal;
		$this->formLoader = $formLoader ?? static fn (int $formId, WebFormSelect $select): ?WebForm =>
			(new FormRepository())->getById($formId, $select)
		;
		$this->activeStateWriter = $activeStateWriter ?? static fn (
			int $formId,
			bool $isActive,
			int $userId,
			?DateTime $expectedModifiedTime,
		): Result => (new FormWriteRepository())
			->setActive($formId, $isActive, $userId, $expectedModifiedTime)
		;
		$this->scriptBuilder = $scriptBuilder ?? static fn (int $formId): bool =>
			(new FormScriptBuilder())->build($formId)
		;
		$this->writeRestriction = $writeRestriction ?? new WebFormWriteRestriction();
		$this->activeFormLimit = $activeFormLimit ?? new ActiveFormLimit(restriction: $this->writeRestriction);
	}

	/**
	 * Brings an existing CRM form into the asked-for state and answers with the state it is in and
	 * the version acting after the call.
	 *
	 * The expected version is optional: a switch is idempotent, so a caller naming the form alone
	 * switches it without reading the form first, and a caller that has read the form guards the
	 * switch with what it read. A call that does write hands the version over to the storage, which
	 * compares it in the statement that writes; a call that writes nothing compares it here, against
	 * the version the form was just read with.
	 *
	 * @return WebForm the form projected onto what this scenario answers for - its identifier, its
	 *         state and its version; nothing else of the form is read back
	 * @throws FormAccessDeniedException the user may not change CRM forms
	 * @throws FormWriteRestrictedException the plan of the portal does not allow writing forms
	 * @throws FormNotFoundException there is no ordinary CRM form under this id
	 * @throws FormSystemImmutableException the form is a system one and can not be changed
	 * @throws FormVersionMismatchException the form was changed after the expected version was read
	 * @throws FormValidationException the form is not fit to be turned on, or the storage refused
	 *         the write; every violation is carried at once and the code of each error is the path
	 *         of the configuration
	 * @throws FormActivationLimitReachedException the portal keeps as many forms working as its plan
	 *         allows
	 * @throws FormSideEffectException the state is written and the package of the form is not
	 *         rebuilt: what the visitors receive stays as it was
	 */
	public function setActive(
		int $userId,
		int $formId,
		bool $isActive,
		?DateTime $expectedModifiedTime = null,
	): WebForm
	{
		$this->assertCanWrite($userId);

		$form = $this->loadForm($formId);
		if ($form->system === true)
		{
			throw new FormSystemImmutableException();
		}

		if ($form->active === $isActive)
		{
			return self::state($formId, $isActive, self::currentVersion($form, $expectedModifiedTime));
		}

		if ($isActive)
		{
			$this->assertFit($userId, $formId);
			$this->assertLimitNotReached();
		}

		$version = $this->write($formId, $isActive, $userId, $expectedModifiedTime);
		$this->buildScripts($formId);

		return self::state($formId, $isActive, $version);
	}

	/**
	 * The right this scenario starts with, asked the way every other write of a form asks it: a
	 * switch of state is a change of the form, so it stands on the right to change forms and on the
	 * plan of the portal allowing one.
	 *
	 * @throws FormAccessDeniedException
	 * @throws FormWriteRestrictedException
	 */
	private function assertCanWrite(int $userId): void
	{
		if (!(bool)($this->permissionChecker)($userId))
		{
			throw new FormAccessDeniedException();
		}

		if (!$this->writeRestriction->isWriteAllowed())
		{
			throw new FormWriteRestrictedException();
		}
	}

	/**
	 * A form of another kind is as absent here as a form that never existed: the caller learns
	 * nothing about which of the two it hit.
	 *
	 * @throws FormNotFoundException
	 */
	private function loadForm(int $formId): WebForm
	{
		$form = ($this->formLoader)($formId, self::createStateSelect());
		if (!$form instanceof WebForm)
		{
			throw new FormNotFoundException();
		}

		return $form;
	}

	/**
	 * The projection this scenario reads the form with: what may not be changed, the state to
	 * compare the asked-for one with, and the version a call changing nothing answers with.
	 */
	private static function createStateSelect(): WebFormSelect
	{
		return WebFormSelect::fromPaths([
			WebForm::FIELD_ID,
			WebForm::FIELD_SYSTEM,
			WebForm::FIELD_ACTIVE,
			WebForm::FIELD_MODIFIED_TIME,
		]);
	}

	/**
	 * A form is turned on only while a write of its own configuration would be taken, and the
	 * verdict is asked of the rules themselves rather than repeated here: this is the same call
	 * crm.form.validate is answered by, over the same stored form.
	 *
	 * @throws FormValidationException
	 */
	private function assertFit(int $userId, int $formId): void
	{
		$verdict = ($this->formChecker)($userId, $formId);
		if (!$verdict->isSuccess())
		{
			throw new FormValidationException(...$verdict->getErrors());
		}
	}

	/**
	 * The limit is asked about only where the number of working forms actually grows, and it is
	 * asked of the same object a creation asks ({@see FormWriter::add()}) and the dictionary
	 * publishes: one comparison rather than several that can drift apart.
	 *
	 * The window between the count and the write is accepted: there is no transaction under this
	 * path, so calls overlapping in it all read the count before any of them writes and all pass:
	 * the number of working forms ends up above the limit by one less than the number of such calls.
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
	 * A refusal of the storage is answered the way a refused write of the configuration is answered
	 * ({@see FormWriter::update()}): the one translation reads the codes of the storage as the paths
	 * of the input, keeps its text inside and writes it to the log. A switch of the state carries no
	 * value of the caller beyond the state itself, so the path it is answered with is the column the
	 * storage refused - and it is one answer for both scenarios rather than two.
	 *
	 * The expected version travels with it and is compared by the storage rather than here: the
	 * comparison and the write are one statement there, so a form changed in between is not switched
	 * instead of being switched over the change.
	 *
	 * A refused write leaves the version where it was: the storage takes its reservation back
	 * together with the row, so the caller still holds the acting version.
	 *
	 * @throws FormValidationException the storage refused the write
	 * @throws FormVersionMismatchException
	 * @throws FormNotFoundException the form was removed after it was read above
	 */
	private function write(
		int $formId,
		bool $isActive,
		int $userId,
		?DateTime $expectedModifiedTime,
	): DateTime
	{
		$result = ($this->activeStateWriter)($formId, $isActive, $userId, $expectedModifiedTime);
		if (!$result->isSuccess())
		{
			throw $this->storageRefusal->toException($result);
		}

		$version = $result->getData()[FormWriteRepository::DATA_MODIFIED_TIME] ?? null;
		if (!$version instanceof DateTime)
		{
			throw new UnexpectedValueException(
				sprintf('Storage of CRM form `%d` answered no version of the written state.', $formId),
			);
		}

		return $version;
	}

	/**
	 * Rebuilds the published package of the form, the way turning a form on has always rebuilt it:
	 * the package decides whether the visitors of the page reach a working form.
	 *
	 * @throws FormSideEffectException
	 */
	private function buildScripts(int $formId): void
	{
		if (!($this->scriptBuilder)($formId))
		{
			throw new FormSideEffectException(FormScriptBuilder::OPERATION);
		}
	}

	/**
	 * The version a call writing nothing answers with, compared with the expected one first: the
	 * comparison belongs here because there is no statement to carry it, and answering success over
	 * a form that has moved on would tell the caller its version is still the acting one.
	 *
	 * @throws FormVersionMismatchException
	 */
	private static function currentVersion(WebForm $form, ?DateTime $expectedModifiedTime): DateTime
	{
		$version = self::versionOf($form);
		if (
			$expectedModifiedTime !== null
			&& $version->getTimestamp() !== $expectedModifiedTime->getTimestamp()
		)
		{
			throw new FormVersionMismatchException($version);
		}

		return $version;
	}

	/**
	 * The version of a form read without one: the projection above asks for it, and a read answering
	 * none leaves this scenario with no version to answer with at all.
	 */
	private static function versionOf(WebForm $form): DateTime
	{
		if (!$form->modifiedTime instanceof DateTime)
		{
			throw new UnexpectedValueException(
				sprintf('CRM form `%d` was read without a version.', $form->id),
			);
		}

		return $form->modifiedTime;
	}

	private static function state(int $formId, bool $isActive, DateTime $version): WebForm
	{
		return new WebForm(id: $formId, active: $isActive, modifiedTime: $version);
	}
}
