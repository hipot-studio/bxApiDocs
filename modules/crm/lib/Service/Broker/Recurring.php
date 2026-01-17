<?php

namespace Bitrix\Crm\Service\Broker;

use Bitrix\Crm\DealRecurTable;
use Bitrix\Crm\Model\Dynamic\RecurringTable;
use Bitrix\Crm\Service\Broker;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use RuntimeException;

class Recurring extends Broker
{
	private Factory $factory;

	public function __construct(int $entityTypeId)
	{
		parent::__construct();

		$this->factory = Container::getInstance()->getFactory($entityTypeId);
	}

	protected function loadEntry(int $id)
	{
		$this->check();

		$entityTypeId = $this->factory->getEntityTypeId();
		$limit = 1;

		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			return DealRecurTable::getList([
				'filter' => [
					'=DEAL_ID' => $id,
				],
				'limit' => $limit,
			])->fetchObject();
		}

		return RecurringTable::getList([
			'filter' => [
				'=ENTITY_TYPE_ID' => $entityTypeId,
				'=ITEM_ID' => $id,
			],
			'limit' => $limit,
		])->fetchObject();
	}

	protected function loadEntries(array $ids): array
	{
		$this->check();

		$entityTypeId = $this->factory->getEntityTypeId();

		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			$list = DealRecurTable::getList([
				'filter' => [
					'@ITEM_ID' => $ids,
				],
			]);
		}
		else
		{
			$list = RecurringTable::getList([
				'filter' => [
					'=ENTITY_TYPE_ID' => $this->factory->getEntityTypeId(),
					'@ITEM_ID' => $ids,
				],
			]);
		}

		$collection = $list->fetchCollection();

		$items = [];
		foreach ($collection as $item)
		{
			$items[$item->getId()] = $item;
		}

		return $items;
	}

	private function check(): void
	{
		if (!$this->factory->isRecurringSupported())
		{
			throw new RuntimeException('Entity type ' . $this->factory->getEntityName() . ' does not support recurring mode');
		}
	}
}
