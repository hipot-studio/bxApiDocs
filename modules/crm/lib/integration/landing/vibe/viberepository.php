<?php

namespace Bitrix\Crm\Integration\Landing\Vibe;

use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Landing\Vibe\Vibe;
use Bitrix\Landing\Vibe\Model\VibeTable;
use Bitrix\Landing\Vibe\Type\Status;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class VibeRepository implements VibeGateway
{
	private static ?bool $isVibeAvailable = null;

	private ?array $vibeCache = null;

	public function findById(int $vibeId): ?VibeReference
	{
		if ($vibeId <= 0 || !self::isVibeAvailable())
		{
			return null;
		}

		$this->vibeCache ??= [];
		if (array_key_exists($vibeId, $this->vibeCache))
		{
			return $this->createReference($this->vibeCache[$vibeId]);
		}

		$vibe = VibeTable::getById($vibeId)->fetch() ?: null;
		$this->vibeCache[$vibeId] = $vibe;

		return $this->createReference($vibe);
	}

	public function findByEmbedId(string $embedId): ?VibeReference
	{
		if ($embedId === '' || !self::isVibeAvailable())
		{
			return null;
		}

		$vibe = VibeTable::query()
			->setSelect(['ID', 'EMBED_ID', 'STATUS'])
			->where('MODULE_ID', 'crm')
			->where('EMBED_ID', $embedId)
			->setLimit(1)
			->fetch()
			?: null;

		return $this->createReference($vibe);
	}

	public function create(string $embedId): ?VibeReference
	{
		if (!self::isVibeAvailable())
		{
			return null;
		}

		$vibe = null;

		try
		{
			$vibe = new Vibe('crm', $embedId);
			if (!$vibe->register(VibeProvider::class))
			{
				return null;
			}

			if (!$vibe->createPageByTemplate())
			{
				$vibe->delete();

				return null;
			}
		}
		catch (\Throwable $exception)
		{
			$this->logException('create', $embedId, $exception);
			if ($vibe !== null)
			{
				try
				{
					$vibe->delete();
				}
				catch (\Throwable $deleteException)
				{
					$this->logException('deleteAfterCreateFailure', $embedId, $deleteException);
				}
			}

			return null;
		}

		return $this->findByEmbedId($embedId);
	}

	public function delete(VibeReference $reference): Result
	{
		if (!self::isVibeAvailable())
		{
			return (new Result())->addError(new Error('Vibe is unavailable.', 'VIBE_UNAVAILABLE'));
		}

		try
		{
			$deleteResult = (new Vibe('crm', $reference->embedId))->delete();
		}
		catch (\Throwable $exception)
		{
			$this->logException('delete', $reference->embedId, $exception);

			return (new Result())->addError(new Error('Unable to delete Vibe.', 'VIBE_DELETION_FAILED'));
		}

		unset($this->vibeCache[$reference->id]);

		return $deleteResult;
	}

	public function getVibeById(int $id): ?array
	{
		if ($id <= 0 || !self::isVibeAvailable())
		{
			return null;
		}

		return $this->getVibesByIds([$id])[$id] ?? null;
	}

	public function getVibesByIds(array $ids): array
	{
		if (!self::isVibeAvailable())
		{
			return [];
		}

		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids))
		{
			return [];
		}

		$this->vibeCache ??= [];
		$idsToLoad = [];
		foreach ($ids as $id)
		{
			if (!array_key_exists($id, $this->vibeCache))
			{
				$idsToLoad[] = $id;
			}
		}

		if (!empty($idsToLoad))
		{
			$dbResult = VibeTable::getList([
				'filter' => ['@ID' => $idsToLoad],
			]);
			while ($vibe = $dbResult->fetch())
			{
				$this->vibeCache[(int)$vibe['ID']] = $vibe;
			}

			foreach ($idsToLoad as $id)
			{
				$this->vibeCache[$id] ??= null;
			}
		}

		return array_intersect_key($this->vibeCache, array_flip($ids));
	}

	private function createReference(?array $vibe): ?VibeReference
	{
		if (empty($vibe) || !isset($vibe['ID'], $vibe['EMBED_ID']))
		{
			return null;
		}

		return new VibeReference(
			(int)$vibe['ID'],
			(string)$vibe['EMBED_ID'],
			($vibe['STATUS'] ?? null) === Status::Created->value,
		);
	}

	public static function isVibeAvailable(): bool
	{
		if (self::$isVibeAvailable === null)
		{
			self::$isVibeAvailable = (
				IntranetManager::isCustomSectionsAvailable()
				&& \Bitrix\Main\Loader::includeModule('landing')
				&& class_exists(\Bitrix\Landing\Vibe\VibeRenderOptions::class)
			);
		}

		return self::$isVibeAvailable;
	}

	private function logException(string $operation, string $embedId, \Throwable $exception): void
	{
		try
		{
			Container::getInstance()->getLogger('Default')->error(
				'Vibe repository operation failed.',
				[
					'operation' => $operation,
					'embedId' => $embedId,
					'exceptionClass' => $exception::class,
					'exceptionMessage' => $exception->getMessage(),
					'exceptionFile' => $exception->getFile(),
					'exceptionLine' => $exception->getLine(),
					'exceptionTrace' => $exception->getTraceAsString(),
				],
			);
		}
		catch (\Throwable)
		{
		}
	}
}
