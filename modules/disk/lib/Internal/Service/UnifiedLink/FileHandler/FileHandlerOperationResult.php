<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use LogicException;
use Throwable;

class FileHandlerOperationResult
{
	private function __construct(
		private readonly ?string $value = null,
		private readonly ?ErrorCollection $errorCollection = null,
		private readonly ?string $redirectUrl = null,
	)
	{
	}

	public static function createSuccess(
		string $value,
		?string $redirectUrl = null,
	): static
	{
		return new static(
			value: $value,
			redirectUrl: $redirectUrl,
		);
	}

	public static function createError(ErrorCollection $errorCollection): static
	{
		return new static(null, $errorCollection);
	}

	public static function createFromException(Throwable $e): static
	{
		$errorCollection = new ErrorCollection();
		$errorCollection->setError(new Error($e->getMessage(), $e->getCode()));
		return new static(null, $errorCollection);
	}

	public function isSuccess(): bool
	{
		return $this->errorCollection === null;
	}

	public function getValue(): string
	{
		if (!$this->isSuccess())
		{
			throw new LogicException('Cannot get value from error result');
		}

		return $this->value;
	}

	public function getErrorCollection(): ErrorCollection
	{
		if ($this->isSuccess())
		{
			throw new LogicException('Cannot get error collection from success result');
		}

		return $this->errorCollection;
	}

	public function getRedirectUrl(): ?string
	{
		return $this->redirectUrl;
	}
}