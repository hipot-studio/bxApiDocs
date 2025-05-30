<?php

namespace Bitrix\Crm\Integration\UI\EntitySelector;

use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

class DynamicProvider extends EntityProvider
{
	public function __construct(array $options = [])
	{
		parent::__construct($options);

		$this->options['dynamicTypeId'] = (int)($options['entityTypeId'] ?? 0);
	}

	public function getRecentItemIds(string $context): array
	{
		if ($this->notLinkedOnly)
		{
			$ids = [];
			$factory = Container::getInstance()->getFactory($this->options['dynamicTypeId']);
			if ($factory)
			{
				$list = $factory->getItemsFilteredByPermissions([
					'order' => ['ID' => 'DESC'],
					'filter' => $this->getNotLinkedFilter(),
				]);

				foreach ($list as $item)
				{
					$ids[] = $item->getId();
				}
			}
		}
		else
		{
			$ids = parent::getRecentItemIds($context);
		}

		return $ids;
	}


	protected function getEntityTypeName(): string
	{
		return 'dynamic';
	}

	protected function getEntityTypeId(): int
	{
		return $this->getOption('dynamicTypeId');
	}

	protected function getEntityTypeNameForMakeItemMethod(): string
	{
		return mb_strtolower(CCrmOwnerType::ResolveName($this->getEntityTypeId()));
	}

	protected function fetchEntryIds(array $filter): array
	{
		$factory = Container::getInstance()->getFactory($this->getEntityTypeId());
		if ($factory)
		{
			$items = $factory->getItemsFilteredByPermissions([
				'select' => ['ID'],
				'filter' => $filter,
			]);

			$result = [];
			foreach ($items as $item)
			{
				$result[] = $item->getId();
			}

			return $result;
		}

		return [];
	}

	protected function getAdditionalFilter(): array
	{
		$filter = [];

		if ($this->notLinkedOnly)
		{
			$filter = $this->getNotLinkedFilter();
		}

		return $filter;
	}
}
