<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Exception\Embedding;

use Bitrix\Main\ObjectNotFoundException;

final class EmbeddingOwnerProviderNotFoundException extends ObjectNotFoundException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct('Embedding owner provider not found', previous: $previous);
	}
}
