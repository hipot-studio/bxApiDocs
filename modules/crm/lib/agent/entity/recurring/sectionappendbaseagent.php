<?php

namespace Bitrix\Crm\Agent\Entity\Recurring;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Integration\UI\EntityEditor\DefaultEntityConfig\SmartInvoiceDefaultEntityConfig;
use Bitrix\Crm\Recurring\RecurringFieldEditorAdapter;
use Bitrix\Main\Config\Option;

abstract class SectionAppendBaseAgent extends AgentBase
{
	private const MODULE_ID = 'crm';
	private const DEFAULT_LIMIT = 100;

	public static function doRun(): bool
	{
		return (new static())->execute();
	}

	abstract protected function getItems(): array;
	abstract protected function getOptionName(): string;
	abstract protected function getPreparedConfig(array $item): array;
	abstract protected function updateConfig(array $item, array $config): void;

	private function execute(): bool
	{
		$items = $this->getItems();

		foreach ($items as $item)
		{
			$config = $this->getPreparedConfig($item);

			if (!is_array($config))
			{
				continue;
			}

			if (!$this->needAppendRecurringField($config))
			{
				continue;
			}

			$needSaveOption = false;
			foreach ($config as &$column)
			{
				if ($column['type'] !== 'column')
				{
					continue;
				}

				if ($column['name'] === 'default_column')
				{
					$column['elements'][] = [
						'name' => RecurringFieldEditorAdapter::SECTION_RECURRING,
						'title' => SmartInvoiceDefaultEntityConfig::getRecurringSectionTitle(),
						'type' => 'section',
						'elements' => [
							['name' => RecurringFieldEditorAdapter::FIELD_RECURRING],
						],
					];

					$needSaveOption = true;

					break;
				}
			}

			unset($column);

			if ($needSaveOption)
			{
				$this->updateConfig($item, $config);
			}
		}

		if (empty($items) || !isset($items['ID']))
		{
			$this->deleteMinId();

			return false;
		}

		$lastItem = end($items);
		$this->setMinId((int)$lastItem['ID']);

		return true;
	}

	protected function getMinId(): int
	{
		return (int)Option::get(self::MODULE_ID, $this->getOptionName(), 0);
	}

	private function setMinId(int $minId): void
	{
		Option::set(self::MODULE_ID,  $this->getOptionName(), $minId);
	}

	protected function deleteMinId(): void
	{
		Option::delete(self::MODULE_ID,  ['name' => $this->getOptionName()]);
	}

	protected function getLimit(): int
	{
		return (int)Option::get(self::MODULE_ID, $this->getOptionName() . 'Limit', self::DEFAULT_LIMIT);
	}

	private function needAppendRecurringField(array $config): bool
	{
		foreach ($config as $column)
		{
			if (!is_array($column) || empty($column['elements']))
			{
				return false;
			}

			foreach ($column['elements'] as $element)
			{
				if (($element['name'] ?? null) === RecurringFieldEditorAdapter::SECTION_RECURRING)
				{
					return false;
				}
			}
		}

		return true;
	}
}
