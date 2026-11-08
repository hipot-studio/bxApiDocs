<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Field;

use RuntimeException;

/**
 * An expected refusal reported by the field requirement domain.
 */
final class RequirementException extends RuntimeException
{
	/**
	 * Creates an exception for the specified domain refusal.
	 */
	public function __construct(private readonly RequirementError $error)
	{
		parent::__construct($error->value);
	}

	/**
	 * Returns the typed domain refusal.
	 */
	public function getError(): RequirementError
	{
		return $this->error;
	}
}
