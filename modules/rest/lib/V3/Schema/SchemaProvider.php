<?php

namespace Bitrix\Rest\V3\Schema;

abstract class SchemaProvider
{
	abstract public function getControllersData(): array;

	abstract public function getDataForDtoGeneration(): array;
}
