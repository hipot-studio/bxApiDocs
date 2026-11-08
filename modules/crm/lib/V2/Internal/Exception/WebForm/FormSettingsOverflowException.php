<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * The settings of the form no longer fit the column holding all of them together.
 *
 * Not a refusal of a field of the input: the size is made up by the whole tree, the sub keys the
 * builder stored years ago included, so no single value sent is the one to blame. It is raised after
 * the version of the form has been reserved, the way every other refusal of the storage is.
 *
 * @internal
 */
final class FormSettingsOverflowException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct(
			'Settings of this CRM form do not fit together any more: all of them stored at once take'
			. ' more than a form can hold.',
		);
	}
}
