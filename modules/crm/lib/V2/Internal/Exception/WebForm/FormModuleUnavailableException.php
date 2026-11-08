<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * A module the CRM form operation depends on is not available, so the operation can not be
 * completed correctly.
 *
 * @internal
 */
final class FormModuleUnavailableException extends RuntimeException
{
	public function __construct(private readonly string $moduleId)
	{
		parent::__construct(sprintf('Module `%s` required by the CRM form operation is unavailable.', $moduleId));
	}

	public function getModuleId(): string
	{
		return $this->moduleId;
	}
}
