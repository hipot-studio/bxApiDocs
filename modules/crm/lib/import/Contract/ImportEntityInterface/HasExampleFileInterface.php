<?php

namespace Bitrix\Crm\Import\Contract\ImportEntityInterface;

interface HasExampleFileInterface
{
	public function getExampleFilePath(): string;
}
