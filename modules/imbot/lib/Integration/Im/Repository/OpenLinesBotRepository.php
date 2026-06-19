<?php

namespace Bitrix\ImBot\Integration\Im\Repository;

use Bitrix\Im\Bot;
use Bitrix\Im\Model\BotTable;
use Bitrix\Im\Model\EO_Bot;
use Bitrix\Im\Model\EO_Bot_Collection;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserTable;

final class OpenLinesBotRepository
{
	public function isExists(mixed $botId): bool
	{
		if (!is_numeric($botId) || (int)$botId <= 0)
		{
			return false;
		}

		return $this->getById($botId) !== null;
	}

	public function getById(int $botId): ?EO_Bot
	{
		if (!self::isModulesIncluded())
		{
			return null;
		}

		return BotTable::query()
			->setSelect([
				'BOT_ID',
				'MODULE_ID',
				'CODE',
				'TYPE',
				'CLASS',
			])
			->where('BOT_ID', $botId)
			->where('TYPE', Bot::TYPE_OPENLINE)
			->setLimit(1)
			->fetchObject();
	}

	public function getByCode(string $code): ?EO_Bot
	{
		if (!self::isModulesIncluded())
		{
			return null;
		}

		return BotTable::query()
			->setSelect([
				'BOT_ID',
				'MODULE_ID',
				'CODE',
				'TYPE',
				'CLASS',
			])
			->where('CODE', $code)
			->where('TYPE', Bot::TYPE_OPENLINE)
			->setLimit(1)
			->fetchObject();
	}

	public function getByClass(string $class, int $limit = 100): ?EO_Bot_Collection
	{
		if (!self::isModulesIncluded())
		{
			return null;
		}

		return BotTable::query()
			->setSelect([
				'BOT_ID',
				'MODULE_ID',
				'CODE',
				'TYPE',
				'CLASS',
			])
			->where('CLASS', $class)
			->where('TYPE', Bot::TYPE_OPENLINE)
			->setLimit($limit)
			->fetchCollection();
	}

	public function getNamesByClassMappedById(string $class, int $limit = 100): ?array
	{
		if (!self::isModulesIncluded())
		{
			return null;
		}

		$result = BotTable::query()
			->where('CLASS', $class)
			->registerRuntimeField(
				'USER',
				new Reference(
					'USER',
					UserTable::class,
					Join::on('this.BOT_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER]
				)
			)
			->setSelect([
				'BOT_ID' => 'BOT_ID',
				'NAME' => 'USER.NAME',
			])
			->addOrder('BOT_ID')
			->setLimit($limit)
			->exec();

		$namesByIds = [];
		while ($row = $result->fetch())
		{
			$id = $row['BOT_ID'] ?? null;
			$name = $row['NAME'] ?? null;
			if ($id && $name)
			{
				$namesByIds[$id] = $name;
			}
		}

		return $namesByIds;
	}

	private static function isModulesIncluded(): bool
	{
		return Loader::includeModule('im');
	}
}
