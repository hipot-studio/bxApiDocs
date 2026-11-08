<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * The form asked to be removed is working right now, and the removal was not confirmed.
 *
 * Neither a refusal of a right nor a fault of the input: the caller gets through by asking for the
 * same removal knowingly. The removal cascade is irreversible, so the refusal is raised before the
 * first destroying step rather than after it.
 *
 * @internal
 */
final class FormRemovalConfirmationRequiredException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('A working CRM form is removed only when the request confirms the removal.');
	}
}
