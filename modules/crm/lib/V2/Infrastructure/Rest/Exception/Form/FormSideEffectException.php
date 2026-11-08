<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use CRestServer;
use Throwable;

/**
 * The write went through and an operation following it did not.
 *
 * A code of its own so that the caller does not read the refusal as "nothing happened": there is
 * no transaction, the form is already created, changed or partly deleted, and the next step is to
 * look at its state rather than to repeat the call.
 *
 * Which operation failed stays inside: the names of the steps are the make-up of the write layer.
 * The original failure travels as the previous exception, and unlike the refusals a client can act
 * on, this one is written to the log - the portal, not the request, is what went wrong.
 *
 * A creation names the form it left behind, the way {@see FormPartiallyCreatedException} does for a
 * refusal of the input: same code as any other failed side effect, so the caller reads "not
 * finished", plus the id, so it reads it about a form it can go and look at. A change and a removal
 * carry no id - the request named the form itself.
 */
final class FormSideEffectException extends RestException
{
	public const CODE = 'CRM_FORM_SIDE_EFFECT_FAILED';

	protected const STATUS = CRestServer::STATUS_INTERNAL;

	public function __construct(?Throwable $previous = null, private readonly ?int $formId = null)
	{
		parent::__construct($previous);
	}

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	public function output(?string $responseLanguage = null): array
	{
		$out = parent::output($responseLanguage);
		if ($this->formId !== null)
		{
			$out['id'] = $this->formId;
		}

		return $out;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_SIDE_EFFECT_FAILED';
	}
}
