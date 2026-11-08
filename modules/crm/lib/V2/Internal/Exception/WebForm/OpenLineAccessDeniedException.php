<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * @internal
 */
final class OpenLineAccessDeniedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Modifying open line chats is denied.');
	}
}
