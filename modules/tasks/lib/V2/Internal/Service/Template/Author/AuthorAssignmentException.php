<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Author;

use Bitrix\Main\Error;
use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;

/**
 * Refusal of the author assignment policy. The policy code is a string,
 * so it is kept apart from the integer exception code.
 */
class AuthorAssignmentException extends TemplateFieldValidateException
{
	private function __construct(string $message, private readonly string $errorCode)
	{
		parent::__construct($message);
	}

	public static function fromError(Error $error): self
	{
		return new self($error->getMessage(), (string)$error->getCode());
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public function toError(): Error
	{
		return new Error($this->getMessage(), $this->errorCode);
	}
}
