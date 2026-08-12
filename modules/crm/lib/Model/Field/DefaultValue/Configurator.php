<?php

namespace Bitrix\Crm\Model\Field\DefaultValue;

interface Configurator
{
	public function getDefaultValue(int $entityTypeId, array ...$args): mixed;
}
