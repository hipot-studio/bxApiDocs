<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormActivationLimitReachedException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormModuleUnavailableException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormNotFoundException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormPartiallyCreatedException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormPhoneVerificationRequiredException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormRemovalConfirmationRequiredException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormSideEffectException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormVersionMismatchException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormWriteRestrictedException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\SystemFormImmutableException;
use Bitrix\Crm\V2\Internal\Exception\WebForm;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Closure;

/**
 * The one translation of the refusals of a write of a CRM form into the answers of REST V3, shared
 * by every write action of the family: the form itself and the embedding of one form.
 *
 * The table lives in one place because a refusal has one meaning wherever it was raised: two copies
 * of it would let the same refusal reach a client as two different answers.
 */
trait TranslatesWriteRefusals
{
	/**
	 * Runs a write and answers its refusals in the one format of REST V3.
	 *
	 * Every refusal is classified by what it means rather than by where it came from: what the
	 * client can fix is a validation answer carrying the paths of the input, what no request of this
	 * client can pass is a refusal with a code of its own, and what belongs to nobody's request is
	 * left to the framework, which turns it into the internal error and writes it to the log.
	 *
	 * @template TResult
	 * @param int|null $formId the form the refusal of an existing one is answered with; absent for
	 *        a creation, which has nothing to name yet
	 * @param Closure(): TResult $operation
	 * @return TResult
	 */
	private function runWrite(?int $formId, Closure $operation): mixed
	{
		try
		{
			return $operation();
		}
		catch (WebForm\FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (WebForm\FormWriteRestrictedException)
		{
			throw new FormWriteRestrictedException();
		}
		catch (WebForm\FormActivationLimitReachedException)
		{
			throw new FormActivationLimitReachedException();
		}
		catch (WebForm\FormPhoneVerificationRequiredException)
		{
			throw new FormPhoneVerificationRequiredException();
		}
		catch (WebForm\FormModuleUnavailableException $exception)
		{
			throw new FormModuleUnavailableException($exception->getModuleId());
		}
		catch (WebForm\FormNotFoundException)
		{
			throw new FormNotFoundException($formId ?? 0);
		}
		catch (WebForm\FormSystemImmutableException)
		{
			throw new SystemFormImmutableException();
		}
		catch (WebForm\FormVersionMismatchException $exception)
		{
			throw new FormVersionMismatchException($exception->getCurrentModifiedTime());
		}
		catch (WebForm\FormRemovalConfirmationRequiredException)
		{
			throw new FormRemovalConfirmationRequiredException();
		}
		catch (WebForm\FormSettingsOverflowException $exception)
		{
			// A validation answer without a field: the size is made up by every setting the form
			// stores together, so there is no value of this request to point the client at.
			throw new RequestValidationException([new Error($exception->getMessage())]);
		}
		catch (WebForm\FormValidationException $exception)
		{
			$errors = $exception->getErrors();
			$createdFormId = $exception->getCreatedFormId();

			throw $createdFormId > 0
				? new FormPartiallyCreatedException($createdFormId, $errors)
				: new RequestValidationException($errors)
			;
		}
		catch (WebForm\FormSideEffectException $exception)
		{
			throw new FormSideEffectException($exception, $exception->getFormId());
		}
	}
}
