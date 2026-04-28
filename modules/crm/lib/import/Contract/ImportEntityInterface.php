<?php

namespace Bitrix\Crm\Import\Contract;

use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;

interface ImportEntityInterface
{
	public function getFields(): FieldCollection;

	public function getSettings(): AbstractImportSettings;

	public function getFieldBindingMapper(): FieldBindingMapperInterface;
}
