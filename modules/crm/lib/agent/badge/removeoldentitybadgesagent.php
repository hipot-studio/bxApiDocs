<?php

namespace Bitrix\Crm\Agent\Badge;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Badge\Model\BadgeTable;
use Bitrix\Crm\Settings\ActivitySettings;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

class RemoveOldEntityBadgesAgent extends AgentBase
{
	public static function doRun(): bool
	{
		$instance = new self();
		$instance->execute();

		return true;
	}

	private function execute(): void
	{
		$limit = self::getLimit();
		$items = BadgeTable::getList([
			'select' => ['ID'],
			'filter' => $this->getFilter(),
			'limit' => $limit + 1,
			'order' => ['CREATED_DATE' => 'ASC'],
		])->fetchAll();

		$ids = array_column($items, 'ID');
		if (count($ids) > $limit)
		{
			BadgeTable::deleteByFilter([
				'@ID' => $ids,
			]);

			$this->setExecutionPeriod(5 * 60); // repeat after 5 minutes
		}
		else
		{
			BadgeTable::deleteByFilter($this->getFilter());
		}
	}

	private function getLimit(): int
	{
		return (int)Option::get('crm', 'RemoveOldEntityBadgesLimit', 100);
	}

	private function getFilter(): array
	{
		$intervalInSeconds = ActivitySettings::getCurrent()->getRemoveEntityBadgesIntervalDays() * 24 * 60 * 60;
		$timestamp = time() + \CTimeZone::getOffset() - $intervalInSeconds;

		return [
			'<=CREATED_DATE' => DateTime::createFromTimestamp($timestamp),
		];
	}
}
