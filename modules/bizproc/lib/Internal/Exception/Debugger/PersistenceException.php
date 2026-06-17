<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Exception\Debugger;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Throwable;

class PersistenceException extends DebuggerException
{
	private array $errors;

	private Result $result;

	public function __construct(string $message, int $code = 0, array $errors = [], ?Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);

		$this->errors = $errors;
	}

	public static function saveError(?Throwable $previous = null): self
	{
		$message = Loc::getMessage('BP_DEBUG_PERSISTENCE_SAVE_ERROR');

		return new self($message, 500, [], $previous);
	}

	public static function deleteError(?Throwable $previous = null): self
	{
		$message = Loc::getMessage('BP_DEBUG_PERSISTENCE_DELETE_ERROR');

		return new self($message, 500, [], $previous);
	}

	public static function readError(?Throwable $previous = null): self
	{
		$message = Loc::getMessage('BP_DEBUG_PERSISTENCE_READ_ERROR');

		return new self($message, 500, [], $previous);
	}

	public function getErrors(): array
	{
		return $this->errors;
	}

	public function getFirstError(): ?string
	{
		return !empty($this->errors) ? reset($this->errors) : null;
	}

	public function hasErrors(): bool
	{
		return !empty($this->errors);
	}

	public function getResult(): Result
	{
		return $this->result;
	}

	public function setResult(Result $result): self
	{
		$this->result = $result;

		return $this;
	}
}
