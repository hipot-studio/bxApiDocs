<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Field\Requirement;

use RuntimeException;

/**
 * An expected refusal reported by the field requirement domain.
 */
final class Exception extends RuntimeException
{
	/**
	 * Creates an exception for the specified domain refusal.
	 */
	public function __construct(private readonly Error $error)
	{
		parent::__construct($error->value);
	}

	/**
	 * Returns the typed domain refusal.
	 */
	public function getError(): Error
	{
		return $this->error;
	}
}
