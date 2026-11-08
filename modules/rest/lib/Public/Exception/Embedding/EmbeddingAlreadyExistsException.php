<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Exception\Embedding;

use Bitrix\Main\SystemException;

final class EmbeddingAlreadyExistsException extends SystemException
{
	public const ERROR_CODE = 'EMBEDDING_ALREADY_EXISTS';

	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(self::ERROR_CODE, previous: $previous);
	}
}
