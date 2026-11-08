<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\FieldRequirement;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

final class ContextNotFoundException extends FieldRequirementException implements SkipWriteToLogException
{
	public function __construct()
	{
		parent::__construct(
			'CONTEXT_NOT_FOUND',
			'The field requirement context was not found.',
			'404 Not Found',
		);
	}
}
