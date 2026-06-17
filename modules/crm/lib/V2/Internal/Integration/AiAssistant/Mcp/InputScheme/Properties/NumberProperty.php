<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

final class NumberProperty extends AbstractProperty
{
	public function getType(): string
	{
		return 'number';
	}
}
