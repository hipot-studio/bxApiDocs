<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Embedding;

use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Exception\ExceptionInterface;

final class EmbeddingHandlerAlreadyBoundException extends SystemException implements ExceptionInterface
{
	public const ERROR_CODE = 'ERROR_EMBEDDING_HANDLER_ALREADY_BOUND';
	public const DEFAULT_MESSAGE = 'The handler is already bound to this placement';

	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(self::DEFAULT_MESSAGE, previous: $previous);
	}
}
