<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Access;

use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Access\FieldAccessContext;
use Bitrix\Rest\V3\Access\FieldAccessProvider;

/**
 * Closes the field methods of the builder resources while the family is disabled for the caller.
 *
 * The field methods are served by the system controller of REST and never pass the entry point of
 * the builder controllers, so the flag is asked here, on every call.
 */
final class BuilderFieldAccessProvider implements FieldAccessProvider
{
	public const ERROR_NOT_PUBLISHED = 'BICONNECTOR_BUILDER_FIELDS_NOT_PUBLISHED';

	private readonly BuilderFamilyGate $familyGate;

	public function __construct(?FlagGate $flagGate = null)
	{
		$this->familyGate = new BuilderFamilyGate($flagGate);
	}

	public function decide(FieldAccessContext $context): Result
	{
		if ($this->familyGate->isEnabled($context->userId))
		{
			return new Result();
		}

		return (new Result())->addError(new Error(
			"The field methods of '{$context->actionUri}' are not published.",
			self::ERROR_NOT_PUBLISHED,
		));
	}
}
