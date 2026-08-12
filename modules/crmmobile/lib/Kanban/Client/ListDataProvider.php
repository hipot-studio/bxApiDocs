<?php

namespace Bitrix\CrmMobile\Kanban\Client;

use Bitrix\Crm\Component\EntityList\ClientDataProvider\GridDataProvider;
use Bitrix\Crm\Field\Collection;
use Bitrix\Crm\Service\Container;

class ListDataProvider extends GridDataProvider
{
	protected ?Collection $fieldsCollection = null;

	public function getFieldsCollection(): Collection
	{
		if ($this->fieldsCollection === null)
		{
			$factory = Container::getInstance()->getFactory($this->clientEntityTypeId);
			$fieldsCollection = $factory?->getFieldsCollection() ?? new Collection([]);
			$entityFieldsMap = $factory?->getFieldsMap() ?? [];
			$defaultDisplayParams = $this->getDefaultDisplayParams();

			$result = [];
			foreach ($fieldsCollection as $commonFieldIdWithoutPrefix => $field)
			{
				if ($commonFieldIdWithoutPrefix === self::ID_FIELD)
				{
					$fieldIdWithoutPrefix = self::RAW_ID_FIELD;
				}
				else
				{
					$fieldIdWithoutPrefix =
						$entityFieldsMap[$commonFieldIdWithoutPrefix]
						?? $commonFieldIdWithoutPrefix
					;
				}

				$fieldId = $this->fieldHelper->addPrefixToFieldId($fieldIdWithoutPrefix);
				$clientField = clone $field;
				$clientField->setName($fieldId);
				$fieldSettings = array_merge(
					$defaultDisplayParams,
					$clientField->getSettings(),
				);
				$clientField->setSettings($fieldSettings);
				$result[$fieldId] = $clientField;
			}

			$this->fieldsCollection = new Collection($result);
		}

		return $this->fieldsCollection;
	}
}
