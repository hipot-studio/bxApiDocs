<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use Bitrix\Main\Error;
use RuntimeException;

/**
 * Domain validation of a CRM form write did not pass.
 *
 * Code of every carried error is the input field path the error belongs to.
 *
 * A refusal of a creation carries one more fact of its own: whether the row of the form was written
 * before the refusal. There is no transaction, so it can be, and a caller told only "invalid" would
 * fix the input, call again and end up with two forms.
 *
 * @internal
 */
final class FormValidationException extends RuntimeException
{
	/** @var Error[] */
	private readonly array $errors;

	/** Form the refused write had already created; zero when nothing was written. */
	private int $createdFormId = 0;

	public function __construct(Error ...$errors)
	{
		$this->errors = $errors;

		parent::__construct('CRM form data is invalid.');
	}

	public static function forPath(string $path, string $message): self
	{
		return new self(new Error($message, $path));
	}

	/**
	 * The refusal of a write that had already created the form.
	 */
	public static function ofPartiallyCreatedForm(int $formId, Error ...$errors): self
	{
		$exception = new self(...$errors);
		$exception->createdFormId = $formId;

		return $exception;
	}

	/**
	 * Form this refusal left behind, zero when the storage was not reached or nothing was written.
	 */
	public function getCreatedFormId(): int
	{
		return $this->createdFormId;
	}

	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	/**
	 * @return string[]
	 */
	public function getFieldPaths(): array
	{
		return array_map(static fn (Error $error): string => (string)$error->getCode(), $this->errors);
	}
}
