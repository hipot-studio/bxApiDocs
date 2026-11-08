<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\FieldRequirement;

final class WriteFailureException extends FieldRequirementException
{
	public function __construct()
	{
		parent::__construct(
			// Keep the existing response code for REST clients.
			'BITRIX_REST_V3_EXCEPTION_INTERNAL_INTERNALEXCEPTION',
			"Something's gone wrong.",
			'500 Internal Server Error',
		);
	}
}
