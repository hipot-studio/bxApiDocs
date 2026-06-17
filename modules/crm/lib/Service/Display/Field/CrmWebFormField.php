<?php

namespace Bitrix\Crm\Service\Display\Field;

use Bitrix\Crm\Service\Display\Options;

class CrmWebFormField extends BaseLinkedEntitiesField
{
	public const TYPE = 'crm_webform';

	public function loadLinkedEntities(array &$linkedEntitiesValues, array $linkedEntity): void
	{
		if ($this->isExportContext())
		{
			return;
		}

		$fieldType = $this->getType();
		if (!isset($linkedEntitiesValues[$fieldType]))
		{
			$linkedEntitiesValues[$fieldType] = \Bitrix\Crm\WebForm\Manager::getListNames();
		}
	}

	protected function renderSingleValue($fieldValue, int $itemId, Options $displayOptions): string
	{
		if (empty($fieldValue))
		{
			return '';
		}

		if ($this->isExportContext())
		{
			return (string)$fieldValue;
		}

		$renderedValue = (string)($this->getLinkedEntitiesValues()[$fieldValue] ?? $fieldValue);
		$this->setWasRenderedAsHtml(true);

		return $this->sanitizeString($renderedValue);
	}
}
