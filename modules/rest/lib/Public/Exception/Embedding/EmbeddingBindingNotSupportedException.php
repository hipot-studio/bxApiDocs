<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Exception\Embedding;

use Bitrix\Main\NotSupportedException;

final class EmbeddingBindingNotSupportedException extends NotSupportedException
{
	public const ERROR_CODE = 'EMBEDDING_BINDING_NOT_SUPPORTED';

	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(self::ERROR_CODE, previous: $previous);
	}
}
