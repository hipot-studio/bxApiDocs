<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\FieldRequirement;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

final class ConfigurationConflictException extends FieldRequirementException implements SkipWriteToLogException
{
	public function __construct()
	{
		parent::__construct(
			'CONFIGURATION_CONFLICT',
			'The field requirement configuration is inconsistent.',
			'409 Conflict',
		);
	}
}
