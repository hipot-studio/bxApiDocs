<?php

namespace Bitrix\Crm\Agent\Entity\Recurring;

use Bitrix\Ui\EntityForm\EntityFormConfigTable;

class SectionAppendToCustomConfigAgent extends SectionAppendBaseAgent
{
	protected function getItems(): array
	{
		return EntityFormConfigTable::getList([
			'filter' => [
				'CATEGORY' => 'crm',
				'%=ENTITY_TYPE_ID' => 'SMART_INVOICE_%',
				'>ID' => $this->getMinId(),
			],
			'order' => ['ID' => 'ASC'],
			'limit' => $this->getLimit(),
		])->fetchAll();
	}

	protected function getOptionName(): string
	{
		return 'AppendRecurringSectionCustomConfig';
	}

	protected function getPreparedConfig(array $item): array
	{
		return is_array($item['CONFIG'] ?? null) ? $item['CONFIG'] : [];
	}

	protected function updateConfig(array $item, array $config): void
	{
		EntityFormConfigTable::update($item['ID'], ['CONFIG' => $config]);
	}
}
