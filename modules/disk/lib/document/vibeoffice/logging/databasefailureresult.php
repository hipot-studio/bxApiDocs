<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

final class DatabaseFailureResult extends Result
{
	private ?string $stage = null;

	private function __construct(private readonly array $diagnosticFields)
	{
		parent::__construct();
	}

	public static function fromException(Throwable $exception, Error $error): Result
	{
		try
		{
			$database = DatabaseErrorDiagnostic::fromException($exception);
			if ($database !== [])
			{
				return (new self(['exception_class' => $exception::class] + $database))->addError($error);
			}
		}
		catch (Throwable)
		{
			// A diagnostic failure must preserve the original operation error.
		}

		return (new Result())->addError($error);
	}

	public function atStage(string $stage): self
	{
		$this->stage = $stage;

		return $this;
	}

	public function getStage(): ?string
	{
		return $this->stage;
	}

	public function getDiagnosticFields(): array
	{
		return $this->diagnosticFields;
	}
}
