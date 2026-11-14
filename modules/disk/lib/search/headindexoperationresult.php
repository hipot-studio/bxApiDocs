<?php

declare(strict_types=1);

namespace Bitrix\Disk\Search;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use UnexpectedValueException;

final class HeadIndexOperationResult
{
	public const OUTCOME_CREATED = 'created';
	public const OUTCOME_UPDATED = 'updated';
	public const OUTCOME_NO_OP = 'no-op';
	public const OUTCOME_SKIPPED = 'skipped';
	public const OUTCOME_ERROR = 'error';

	/**
	 * @param Error[] $errors
	 */
	private function __construct(
		private readonly string $outcome,
		private readonly array $errors = [],
	)
	{
	}

	public static function fromPersistenceResult(Result $result): self
	{
		if (!$result->isSuccess())
		{
			return new self(self::OUTCOME_ERROR, $result->getErrors());
		}

		$outcome = $result->getData()['outcome'] ?? null;
		if (!is_string($outcome) || !in_array($outcome, self::getSuccessfulOutcomes(), true))
		{
			throw new UnexpectedValueException('Unknown head index persistence outcome');
		}

		return new self($outcome);
	}

	public function getOutcome(): string
	{
		return $this->outcome;
	}

	public function isSuccess(): bool
	{
		return $this->outcome !== self::OUTCOME_ERROR;
	}

	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	private static function getSuccessfulOutcomes(): array
	{
		return [
			self::OUTCOME_CREATED,
			self::OUTCOME_UPDATED,
			self::OUTCOME_NO_OP,
			self::OUTCOME_SKIPPED,
		];
	}
}
