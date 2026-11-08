<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Crm\V2\Internal\Exception\WebForm\FormValidationException;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * `crm.form.add` refused the input after the row of the form was already written.
 *
 * There is no transaction, so a section rejected after the row leaves a form behind. Answering
 * that with a plain validation refusal would read as "nothing was created", and a caller acting on
 * it would fix the input, call again and end up with two forms. So the id of what exists is part
 * of the answer, next to the field errors, and the code says which of the two refusals this is.
 *
 * Which form exists is asked of the refusal of the write layer ({@see
 * FormValidationException::getCreatedFormId()}), not read out of the errors it carries: the
 * transport reads a typed answer of the layer below instead of unpacking data of its storage.
 */
final class FormPartiallyCreatedException extends RequestValidationException
{
	public const CODE = 'CRM_FORM_PARTIALLY_CREATED';

	/**
	 * @param Error[] $errors
	 */
	public function __construct(private readonly int $id, array $errors)
	{
		parent::__construct($errors);
	}

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	public function output($responseLanguage = null): array
	{
		$out = parent::output($responseLanguage);
		$out['id'] = $this->id;

		return $out;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_PARTIALLY_CREATED';
	}

	protected function getClassWithPhrase(): string
	{
		return self::class;
	}
}
