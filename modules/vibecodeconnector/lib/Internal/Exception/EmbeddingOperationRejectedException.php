<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

final class EmbeddingOperationRejectedException extends CodedSystemException
{
	public function __construct(
		string $message = '',
		?string $errorCode = null,
		private readonly ?string $field = null,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, $errorCode, previous: $previous);
	}

	public function getField(): ?string
	{
		return $this->field;
	}
}
