<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Exception\Embedding;

use Bitrix\Main\ObjectNotFoundException;

final class EmbeddingNotFoundException extends ObjectNotFoundException
{
	public const ERROR_CODE = 'EMBEDDING_NOT_FOUND';

	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(self::ERROR_CODE, previous: $previous);
	}
}
