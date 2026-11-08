<?php

declare(strict_types=1);

namespace Bitrix\Rest\Update;

use Bitrix\Main\Loader;
use Bitrix\Main\Update\Stepper;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingAccessSynchronizer;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;
use Bitrix\Rest\PlacementTable;

final class BackfillEmbeddingAccess extends Stepper
{
	protected static $moduleId = 'rest';

	private const LIMIT = 50;

	public function __construct(
		private readonly EmbeddingAccessSynchronizer $synchronizer = new EmbeddingAccessSynchronizer(),
	)
	{
	}

	public function execute(array &$option): bool
	{
		if (!Loader::includeModule('rest'))
		{
			return self::FINISH_EXECUTION;
		}

		$this->initializeOption($option);

		$rows = $this->getRows((int)$option['lastId']);
		if (empty($rows))
		{
			return self::FINISH_EXECUTION;
		}

		$appIdByEmbeddingId = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$appId = EmbeddingOwnerResolver::resolveAppId($row);
			if ($appId !== null)
			{
				$appIdByEmbeddingId[$id] = $appId;
			}

			$option['lastId'] = $id;
			$option['steps']++;
		}

		$this->synchronizer->syncApplicationEmbeddings($appIdByEmbeddingId);

		return count($rows) === self::LIMIT ? self::CONTINUE_EXECUTION : self::FINISH_EXECUTION;
	}

	private function initializeOption(array &$option): void
	{
		if (!empty($option))
		{
			return;
		}

		$option['lastId'] = 0;
		$option['steps'] = 0;
		$option['count'] = PlacementTable::getCount();
	}

	private function getRows(int $lastId): array
	{
		return PlacementTable::query()
			->setSelect(EmbeddingOwnerResolver::OWNER_SELECT)
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::LIMIT)
			->fetchAll()
		;
	}

}
