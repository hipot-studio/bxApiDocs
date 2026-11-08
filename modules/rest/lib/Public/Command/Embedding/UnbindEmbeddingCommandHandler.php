<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Embedding;

use Bitrix\Rest\Internal\Repository\EmbeddingRepository;
use Bitrix\Rest\Public\Exception\Embedding\EmbeddingNotFoundException;

final class UnbindEmbeddingCommandHandler
{
	public function __construct(private EmbeddingRepository $repository = new EmbeddingRepository())
	{
	}

	public function __invoke(UnbindEmbeddingCommand $command): void
	{
		if (!$this->repository->deleteById($command->embeddingId))
		{
			throw new EmbeddingNotFoundException();
		}
	}
}
