<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;
use Throwable;

/**
 * A side effect operation of a CRM form write failed and is not hidden from the caller.
 *
 * A creation names the form it left behind. There is no transaction, so the form is already
 * written when a side effect of the creation fails, and the caller has no other way of learning
 * its id - a repeated call would create a second form. A change and a removal name none: the
 * caller addressed the form itself.
 *
 * @internal
 */
final class FormSideEffectException extends RuntimeException
{
	public function __construct(
		private readonly string $operation,
		private readonly ?int $formId = null,
		?Throwable $previous = null,
	)
	{
		parent::__construct(sprintf('CRM form side effect operation `%s` has failed.', $operation), 0, $previous);
	}

	public function getOperation(): string
	{
		return $this->operation;
	}

	/**
	 * The form that exists despite the failure, or null when the caller already knows which form
	 * this was about.
	 */
	public function getFormId(): ?int
	{
		return $this->formId;
	}
}
