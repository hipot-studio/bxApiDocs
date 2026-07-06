<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingCollection;
use Bitrix\Rest\Internal\Repository\EmbeddingRepository;

class EmbeddingProvider
{
	private EmbeddingRepository $repository;

	public function __construct(
		?EmbeddingRepository $repository = null,
	)
	{
		$this->repository = $repository ?? ServiceLocator::getInstance()->get(EmbeddingRepository::class);
	}

	public function getListByClientId(string $clientId, int $limit = 50, int $offset = 0): EmbeddingCollection
	{
		return $this->repository->getListByClientId($clientId, $limit, $offset);
	}
}
