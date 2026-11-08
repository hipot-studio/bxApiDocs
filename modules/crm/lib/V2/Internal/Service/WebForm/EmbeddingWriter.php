<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\ViewOptions;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormPhoneVerificationRequiredException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSettingsOverflowException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSideEffectException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSystemImmutableException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormValidationException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormVersionMismatchException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormWriteRestrictedException;
use Bitrix\Crm\V2\Internal\Integration\Bitrix24\WebFormWriteRestriction;
use Bitrix\Crm\V2\Internal\Repository\WebForm\EmbeddingRepository;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormWriteRepository;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use UnexpectedValueException;

/**
 * Writes the settings of the embedding variants of a CRM form. Mirror of {@see EmbeddingReader} on
 * the writing side, and a scenario of its own rather than a section of the write of the form: the
 * settings are read by a method of their own, so they are written by one too.
 *
 * The order of the steps repeats the change of a form: the right to change CRM forms, then what the
 * plan of the portal allows, then the confirmed phone number the portal demands, then the existence
 * and the admissibility of the form, then the domain check of the input, and only then the write.
 * Nothing is written once anything has been refused.
 *
 * The published package of the form is rebuilt once something was written, the way the editor of the
 * embedding rebuilds it: the package carries these settings as they were when it was built, so a
 * write without a rebuild would be answered by the read while the visitors of the page the form is
 * placed on keep receiving the previous ones. A change set carrying nothing rebuilds nothing.
 *
 * Success is the absence of an exception, and the version of the form is not optional: a form has
 * one rule of concurrent change, and this scenario answers with the same conflict as the change of
 * the form itself.
 *
 * @internal
 */
final class EmbeddingWriter
{
	private const LOGGER_ID = 'Default';

	private readonly \Closure $permissionChecker;
	private readonly \Closure $formLoader;
	private readonly \Closure $viewOptionsLoader;
	private readonly \Closure $embeddingUpdater;
	private readonly \Closure $scriptBuilder;
	private readonly WebFormWriteRestriction $writeRestriction;

	/**
	 * @param null|\Closure(int): bool $permissionChecker
	 * @param null|\Closure(int): ?WebForm $formLoader
	 * @param null|\Closure(string): ViewOptions $viewOptionsLoader
	 * @param null|\Closure(int, EmbeddingChangeSet, DateTime): Result $embeddingUpdater
	 * @param null|\Closure(int): bool $scriptBuilder
	 */
	public function __construct(
		?\Closure $permissionChecker = null,
		?\Closure $formLoader = null,
		?\Closure $viewOptionsLoader = null,
		?\Closure $embeddingUpdater = null,
		?\Closure $scriptBuilder = null,
		?WebFormWriteRestriction $writeRestriction = null,
	)
	{
		$this->permissionChecker = $permissionChecker ?? static fn (int $userId): bool =>
			Container::getInstance()
				->getUserPermissions($userId)
				->webForm()
				->canEdit()
		;
		$this->formLoader = $formLoader ?? static fn (int $formId): ?WebForm =>
			(new FormRepository())->getById($formId, self::createCurrentStateSelect())
		;
		$this->viewOptionsLoader = $viewOptionsLoader ?? static fn (string $variant): ViewOptions =>
			(new EmbeddingRepository())->getViewOptions($variant)
		;
		$this->embeddingUpdater = $embeddingUpdater ?? static fn (
			int $formId,
			EmbeddingChangeSet $changeSet,
			DateTime $expectedModifiedTime,
		): Result => (new FormWriteRepository())->updateEmbedding($formId, $changeSet, $expectedModifiedTime);
		$this->scriptBuilder = $scriptBuilder ?? static fn (int $formId): bool =>
			(new FormScriptBuilder())->build($formId)
		;
		$this->writeRestriction = $writeRestriction ?? new WebFormWriteRestriction();
	}

	/**
	 * Applies the change set to the embedding settings of an existing CRM form. What the change set
	 * does not carry is left as it is - the settings of the other variants included.
	 *
	 * A change set carrying nothing writes nothing: it is answered by the checks alone, the version
	 * of the form among them.
	 *
	 * @throws FormAccessDeniedException the user may not change CRM forms
	 * @throws FormWriteRestrictedException the plan of the portal does not allow writing forms
	 * @throws FormPhoneVerificationRequiredException the portal asks for a confirmed phone number
	 * @throws FormNotFoundException there is no ordinary CRM form under this id
	 * @throws FormSystemImmutableException the form is a system one and can not be changed
	 * @throws FormVersionMismatchException the form was changed after the expected version was read
	 * @throws FormValidationException a setting carries a value the portal does not allow or longer
	 *         than it is bounded by; every violation is carried at once and the code of each error is
	 *         the input path
	 * @throws FormSettingsOverflowException the settings of the form no longer fit the storage
	 * @throws FormSideEffectException the settings are written and the package of the form is not
	 *         rebuilt: what the visitors receive stays as it was
	 */
	public function updateEmbedding(
		int $userId,
		int $formId,
		EmbeddingChangeSet $changeSet,
		DateTime $expectedModifiedTime,
	): void
	{
		$this->assertCanWrite($userId);
		$this->assertPhoneVerified($formId);

		$form = $this->loadForm($formId);
		if ($form->system === true)
		{
			throw new FormSystemImmutableException();
		}

		$this->validate($changeSet);

		$this->write($formId, $changeSet, $expectedModifiedTime);
		$this->buildScripts($formId, $changeSet);
	}

	/**
	 * The right this scenario starts with: may this user change CRM forms at all.
	 *
	 * Published for the same reason {@see FormWriter::assertCanEdit()} is: a caller reading the
	 * input first would answer a user without the right with the make-up of a contract they may not
	 * use. The scenario asks it again on its own - the order of a caller is not what the write
	 * stands on.
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

	/**
	 * The restriction only reports what the plan of the portal demands; refusing the write is the
	 * decision of this scenario. A portal demanding nothing is never asked about a particular form.
	 */
	private function assertPhoneVerified(int $formId): void
	{
		if (!$this->writeRestriction->isPhoneVerified($formId))
		{
			throw new FormPhoneVerificationRequiredException();
		}
	}

	/**
	 * A form of another kind is as absent here as a form that never existed: the caller learns
	 * nothing about which of the two it hit.
	 */
	private function loadForm(int $formId): WebForm
	{
		$form = ($this->formLoader)($formId);
		if (!$form instanceof WebForm)
		{
			throw new FormNotFoundException();
		}

		return $form;
	}

	/**
	 * The projection of the stored form this scenario needs: what may not be changed. The settings
	 * of the variants themselves take no part in the check - the input replaces the values it sends
	 * and nothing else, so what the form already holds neither allows nor refuses anything.
	 */
	private static function createCurrentStateSelect(): WebFormSelect
	{
		return WebFormSelect::fromPaths([WebForm::FIELD_ID, WebForm::FIELD_SYSTEM]);
	}

	/**
	 * Refuses every setting whose value is outside the values the portal publishes for it or longer
	 * than the setting is bounded by, and refuses them all at once: a caller learns about every
	 * violation instead of fixing one per request.
	 *
	 * The values are asked of the same source the read publishes beside the settings, so a client is
	 * never refused a value it was offered. A setting being cleared is checked against neither of the
	 * two - naming no value is a state of the form, not a value outside the list. A published list
	 * that is empty is the dictionary of the portal carrying nothing, and nothing is what it can
	 * refuse.
	 *
	 * A setting the dictionary publishes no values for is bounded in length instead, and the length
	 * is the bound of the setting alone: the settings of every variant share one serialized column
	 * with the rest of the settings of the form, and a value bounded by nothing is a way to lose all
	 * of them at once.
	 *
	 * A colour is checked against the form it is written in on top of that length. The published
	 * package composes the hover rule of the button as CSS text and appends the stored value to it
	 * as it is, so a colour of another form is not a colour that fails to apply: it is a rule of its
	 * own on every page the form is placed on.
	 *
	 * @throws FormValidationException
	 */
	private function validate(EmbeddingChangeSet $changeSet): void
	{
		$errors = [];
		$optionsByVariant = [];
		foreach ($changeSet->paths() as $path)
		{
			$value = $changeSet->get($path);
			if ($value === null)
			{
				continue;
			}

			$maxLength = EmbeddingChangeSet::maxLengthOf($path);
			if ($maxLength !== null && mb_strlen((string)$value) > $maxLength)
			{
				$errors[] = new Error(
					sprintf('`%s` is longer than %d characters', $path, $maxLength),
					$path,
				);

				continue;
			}

			$pattern = EmbeddingChangeSet::patternOf($path);
			if ($pattern !== null && preg_match($pattern, (string)$value) !== 1)
			{
				$errors[] = new Error(
					sprintf('`%s` expects a hex colour, `#3bc8f5` or `#3eddffa6`', $path),
					$path,
				);

				continue;
			}

			$enumeration = EmbeddingChangeSet::enumerationOf($path);
			if ($enumeration === null)
			{
				continue;
			}

			$variant = EmbeddingChangeSet::variantOf($path);
			$options = $optionsByVariant[$variant] ??= ($this->viewOptionsLoader)($variant);
			$allowed = $options->values($enumeration);
			if ($allowed === null || $allowed === [] || in_array($value, $allowed, true))
			{
				continue;
			}

			$errors[] = new Error(
				sprintf('`%s` accepts one of: %s', $path, implode(', ', array_map('strval', $allowed))),
				$path,
			);
		}

		if ($errors !== [])
		{
			throw new FormValidationException(...$errors);
		}
	}

	/**
	 * The storage writes the merged settings column and no value of the input reaches a column of
	 * its own, so a refusal of the storage is not a refusal of a field the caller sent: it is a
	 * failure of the operation, reported as one and written to the log instead of to the caller.
	 */
	private function write(
		int $formId,
		EmbeddingChangeSet $changeSet,
		DateTime $expectedModifiedTime,
	): void
	{
		$result = ($this->embeddingUpdater)($formId, $changeSet, $expectedModifiedTime);
		if ($result->isSuccess())
		{
			return;
		}

		Container::getInstance()->getLogger(self::LOGGER_ID)->error(
			'CRM form embedding write was refused by the storage of form `{formId}`: {message}',
			['formId' => $formId, 'message' => implode('; ', $result->getErrorMessages())],
		);

		throw new UnexpectedValueException(
			sprintf('Embedding settings of CRM form `%d` were not stored.', $formId),
		);
	}

	/**
	 * Rebuilds the published package of the form, the way the editor of the embedding does after a
	 * save of its own: the package carries these settings as they were when it was built.
	 *
	 * A change set carrying nothing wrote nothing, so there is nothing to rebuild: the scenario was
	 * answered by the checks alone.
	 *
	 * @throws FormSideEffectException
	 */
	private function buildScripts(int $formId, EmbeddingChangeSet $changeSet): void
	{
		if ($changeSet->isEmpty())
		{
			return;
		}

		if (!($this->scriptBuilder)($formId))
		{
			throw new FormSideEffectException(FormScriptBuilder::OPERATION);
		}
	}
}
