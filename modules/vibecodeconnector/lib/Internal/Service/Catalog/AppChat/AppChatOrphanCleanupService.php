<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Cleanup\ChatContentCollector;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\CatalogItemTable;

final class AppChatOrphanCleanupService
{
	private const GRACE_PERIOD_SECONDS = 600;
	private const BATCH_SIZE = 100;

	public function __construct(
		private readonly int $gracePeriodSeconds = self::GRACE_PERIOD_SECONDS,
		private readonly int $batchSize = self::BATCH_SIZE,
	) {}

	public function run(): void
	{
		$this->cleanupBatch($this->gracePeriodSeconds, false);
	}

	public function drain(): void
	{
		do
		{
			$processed = $this->cleanupBatch(0, true);
		}
		while ($processed >= max(1, $this->batchSize));
	}

	private function cleanupBatch(int $gracePeriodSeconds, bool $failOnError): int
	{
		$rows = ChatTable::query()
			->setSelect(['ID', 'AUTHOR_ID'])
			->where('TYPE', Chat::IM_TYPE_EXTERNAL)
			->where('ENTITY_TYPE', AppChatType::ENTITY_TYPE)
			->where('DATE_CREATE', '<=', DateTime::createFromTimestamp(time() - max(0, $gracePeriodSeconds)))
			->registerRuntimeField(new Reference(
				'BOUND_ITEM',
				CatalogItemTable::class,
				Join::on('this.ID', 'ref.MESSENGER_CHAT_ID'),
				['join_type' => Join::TYPE_LEFT],
			))
			->whereNull('BOUND_ITEM.ID')
			->setOrder(['ID' => 'ASC'])
			->setLimit(max(1, $this->batchSize))
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$chatId = (int)$row['ID'];
			if ($chatId <= 0)
			{
				continue;
			}

			try
			{
				(new ChatContentCollector($chatId))->deleteChat((int)$row['AUTHOR_ID']);
			}
			catch (\Throwable $exception)
			{
				if ($failOnError)
				{
					throw $exception;
				}
				\AddMessage2Log(
					"App chat {$chatId}: orphan cleanup failed, {$exception->getMessage()}",
					'vibecodeconnector',
				);
			}
		}

		return count($rows);
	}
}
