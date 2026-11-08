<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Field;

use RuntimeException;
use Throwable;

/**
 * An unexpected technical failure reported while reading a field requirement.
 */
final class RequirementTechnicalException extends RuntimeException
{
	private const MESSAGE = 'Unable to read field requirement.';

	/**
	 * Creates a neutral public exception while preserving the original failure for diagnostics.
	 */
	public function __construct(Throwable $previous)
	{
		parent::__construct(self::MESSAGE, 0, $previous);
	}
}
