<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Application;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;
use Bitrix\Rest\Marketplace\Url;
use Bitrix\Rest\Public\Dto\Embedding\EmbeddingOwnerDto;
use Bitrix\Rest\Public\Exception\Embedding\EmbeddingBindingNotSupportedException;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingOpenDto;
use Bitrix\Rest\Public\Provider\EmbeddingOwnerProviderInterface;

final class AppEmbeddingOwnerProvider implements EmbeddingOwnerProviderInterface
{
	private AppRepository $repository;

	public function __construct(?AppRepository $repository = null)
	{
		$this->repository = $repository ?? ServiceLocator::getInstance()->get('rest.repository.app');
	}

	public function resolveEmbeddings(array $embeddings, int $userId): array
	{
		$appIds = [];
		$appIdByEmbeddingId = [];
		foreach ($embeddings as $embedding)
		{
			$appId = EmbeddingOwnerResolver::parseAppId($embedding->entityId);
			if ($appId === null)
			{
				continue;
			}

			$appIds[$appId] = $appId;
			$appIdByEmbeddingId[$embedding->id] = $appId;
		}

		if (empty($appIds))
		{
			return [];
		}

		$apps = $this->repository->getByIds(array_values($appIds));

		$usableApps = [];
		foreach ($apps as $app)
		{
			if (!$app->isInstalled() || !$app->isActive())
			{
				continue;
			}

			$usableApps[$app->getId()] = $app;
		}
		if (empty($usableApps))
		{
			return [];
		}

		$result = [];
		foreach ($embeddings as $embedding)
		{
			$appId = $appIdByEmbeddingId[$embedding->id] ?? null;
			$app = $appId === null ? null : ($usableApps[$appId] ?? null);

			if ($app === null)
			{
				continue;
			}

			$result[] = new EmbeddingOpenDto(
				embeddingId: $embedding->id,
				url: Url::getApplicationUrl($app->getId()),
				payload: [
					'ID' => $app->getId(),
					'PLACEMENT' => $embedding->placement,
					'PLACEMENT_ID' => $embedding->id,
					'POPUP' => 1,
				],
			);
		}

		return $result;
	}

	public function getEmbeddingAccessCodes(EmbeddingOwnerDto $owner): array
	{
		throw new EmbeddingBindingNotSupportedException();
	}
}
