<?php

namespace Bitrix\Bizproc\Internal\Event;

use Bitrix\Main\Event;

class SetupTemplateUserInputEvent extends Event
{
	public const MODULE_ID = 'bizproc';
	public const EVENT_NAME = 'SetupTemplateUserInput';
	public const PARAMETER_CONSTANT_VALUES = 'constantValues';
	public const PARAMETER_TEMPLATE_ID = 'templateId';
	public const PARAMETER_USER_ID = 'userId';
	public const PARAMETER_INSTANCE_ID = 'instanceId';

	public function __construct(
		string $moduleId = self::MODULE_ID,
		string $type = self::EVENT_NAME,
		array $parameters = [],
		$filter = null
	)
	{
		parent::__construct($moduleId, $type, $parameters, $filter);
	}
}