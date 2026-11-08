<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Embedding;

use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Repository\Application\AppSelect;
use Bitrix\Rest\Internal\Repository\EmbeddingAccessRepository;
use Bitrix\Rest\PlacementTable;

class EmbeddingAccessSynchronizer
{
	public function __construct(
		private ?AppRepository $appRepository = null,
		private readonly AppEmbeddingAccessCodeResolver $codeResolver = new AppEmbeddingAccessCodeResolver(),
		private readonly EmbeddingAccessRepository $accessRepository = new EmbeddingAccessRepository(),
	)
	{
	}

	public function syncEmbedding(int $embeddingId): void
	{
		try
		{
			$row = $this->getRow($embeddingId);
		}
		catch (\Throwable $exception)
		{
			$this->journal($exception);

			return;
		}

		$appId = $row === null ? null : EmbeddingOwnerResolver::resolveAppId($row);
		if ($appId === null)
		{
			$this->deleteCodesOfEmbedding($embeddingId);

			return;
		}

		$this->syncApplicationEmbeddings([$embeddingId => $appId]);
	}

	public function syncApp(int $appId): void
	{
		try
		{
			$embeddingIds = $this->accessRepository->findEmbeddingIdsByApp($appId);
		}
		catch (\Throwable $exception)
		{
			$this->journal($exception);

			return;
		}

		$this->syncApplicationEmbeddings(array_fill_keys($embeddingIds, $appId));
	}

	/**
	 * @param array<int, int> $appEmbeddingMap embeddingId => appId
	 */
	public function syncApplicationEmbeddings(array $appEmbeddingMap): void
	{
		if (empty($appEmbeddingMap))
		{
			return;
		}

		$embeddingIds = array_keys($appEmbeddingMap);

		$this->runSafely(
			fn() => $this->applyAccessCodes($appEmbeddingMap),
			fn() => $this->revokeAccessCodes($embeddingIds),
		);
	}

	public function deleteCodesOfEmbedding(int $embeddingId): void
	{
		$this->deleteCodesOfEmbeddings([$embeddingId]);
	}

	public function deleteCodesOfEmbeddings(array $embeddingIds): void
	{
		$this->runSafely(fn() => $this->accessRepository->deleteForEmbeddings($embeddingIds));
	}

	public function deleteCodesOfApp(int $appId): void
	{
		$this->runSafely(fn() => $this->accessRepository->deleteForApp($appId));
	}

	private function applyAccessCodes(array $appEmbeddingMap): void
	{
		$appIds = array_values(array_unique($appEmbeddingMap));
		$apps = $this->getApps($appIds);
		$accessCodesByAppId = array_fill_keys($appIds, []);
		foreach ($apps as $appId => $app)
		{
			$accessCodesByAppId[$appId] = $this->codeResolver->resolve($app);
		}

		$accessCodesByEmbeddingId = [];
		foreach ($appEmbeddingMap as $embeddingId => $appId)
		{
			$accessCodesByEmbeddingId[$embeddingId] = $accessCodesByAppId[$appId] ?? [];
		}

		$this->setAccessCodes($accessCodesByEmbeddingId);
	}

	private function revokeAccessCodes(array $embeddingIds): void
	{
		$this->runSafely(fn() => $this->setAccessCodes(array_fill_keys($embeddingIds, [])));
	}

	private function runSafely(\Closure $action, ?\Closure $onFailure = null): void
	{
		try
		{
			$action();
		}
		catch (\Throwable $exception)
		{
			$this->journal($exception);

			if ($onFailure !== null)
			{
				$onFailure();
			}
		}
	}

	private function setAccessCodes(array $accessCodesByEmbeddingId): void
	{
		$this->accessRepository->replaceForEmbeddings(
			EmbeddingOwnerResolver::MODULE_ID_REST,
			EmbeddingOwnerResolver::ENTITY_TYPE_APP,
			EmbeddingAccessCodeNormalizer::normalizeMap($accessCodesByEmbeddingId),
		);
	}

	private function journal(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}

	private function getApps(array $appIds): array
	{
		$apps = [];
		foreach ($this->getAppRepository()->getByIds($appIds, (new AppSelect())->attributes()) as $app)
		{
			$apps[(int)$app->getId()] = $app;
		}

		return $apps;
	}

	private function getAppRepository(): AppRepository
	{
		return $this->appRepository ??= ServiceLocator::getInstance()->get('rest.repository.app');
	}

	private function getRow(int $embeddingId): ?array
	{
		return PlacementTable::query()
			->setSelect(EmbeddingOwnerResolver::OWNER_SELECT)
			->where('ID', $embeddingId)
			->setLimit(1)
			->fetch() ?: null;
	}
}
