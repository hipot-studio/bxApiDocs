<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing;

/**
 * A read of a source that failed for some users of the pack and answered the rest. The evaluator fails
 * only the users named here and judges the others on their values.
 */
final class PartialReadException extends \RuntimeException
{
	/**
	 * @param array<int, mixed> $values userId => value for the users that were read; null is unknown
	 * @param non-empty-array<int, \Throwable> $failures userId => what the read of that user met
	 */
	public function __construct(private readonly array $values, private readonly array $failures)
	{
		parent::__construct('The fact could not be read for ' . count($failures) . ' user(s)');
	}

	/**
	 * @return array<int, mixed>
	 */
	public function getValues(): array
	{
		return $this->values;
	}

	/**
	 * @return array<int, \Throwable>
	 */
	public function getFailures(): array
	{
		return $this->failures;
	}
}
