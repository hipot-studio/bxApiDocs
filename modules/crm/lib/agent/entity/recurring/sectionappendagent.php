<?php

namespace Bitrix\Crm\Agent\Entity\Recurring;

use Bitrix\Crm\Service\Container;
use CUserOptions;

class SectionAppendAgent extends SectionAppendBaseAgent
{
	protected function getItems(): array
	{
		$optionName = $this->getConfigOptionName();
		if ($optionName === null)
		{
			return [];
		}

		$option = CUserOptions::GetOption('crm.entity.editor', $optionName, null);

		return is_array($option) ? [$option] : [];
	}

	protected function getOptionName(): string
	{
		return 'AppendRecurringSection';
	}

	protected function getPreparedConfig(array $item): array
	{
		return $item;
	}

	protected function updateConfig(array $item, array $config): void
	{
		$optionName = $this->getConfigOptionName();
		if ($optionName === null)
		{
			return;
		}

		CUserOptions::SetOption('crm.entity.editor', $optionName, $config, true);
	}

	private function getConfigOptionName(): ?string
	{
		$factory = Container::getInstance()->getFactory(\CCrmOwnerType::SmartInvoice);
		if ($factory === null)
		{
			return null;
		}

		$categoryId = $factory->getDefaultCategory()?->getId();

		return "smart_invoice_details_c{$categoryId}_common";
	}

	protected function deleteMinId(): void
	{
		// nothing to do
	}
}
