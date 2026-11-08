<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

use Bitrix\Main\ObjectNotFoundException;

final class EmbeddingNotFoundException extends ObjectNotFoundException
{
	public function __construct(
		public readonly int $embeddingId,
		?\Throwable $previous = null,
	)
	{
		parent::__construct("Embedding {$embeddingId} not found", $previous);
	}
}
