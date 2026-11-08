<?php

namespace Bitrix\Crm\AutomatedSolution\Vibe;

use Bitrix\Main\Error;

final class StateWriteResult
{
	public const APPLIED = 'applied';
	public const CONDITION_MISMATCH = 'condition_mismatch';
	public const NOT_FOUND = 'not_found';
	public const FAILURE = 'failure';

	private function __construct(
		public readonly string $status,
		public readonly ?Error $error = null,
	)
	{
	}

	public static function applied(): self
	{
		return new self(self::APPLIED);
	}

	public static function conditionMismatch(): self
	{
		return new self(self::CONDITION_MISMATCH);
	}

	public static function notFound(): self
	{
		return new self(self::NOT_FOUND);
	}

	public static function failure(Error $error): self
	{
		return new self(self::FAILURE, $error);
	}

	public function isApplied(): bool
	{
		return $this->status === self::APPLIED;
	}
}
