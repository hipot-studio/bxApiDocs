<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Main\UuidGenerator;

final class AuditOperationContextStack
{
	/** @var AuditOperationContext[] */
	private static array $stack = [];

	private function __construct()
	{
	}

	public static function current(): ?AuditOperationContext
	{
		if (self::$stack === [])
		{
			return null;
		}

		return self::$stack[array_key_last(self::$stack)];
	}

	public static function push(AuditOperationContext $context): void
	{
		self::$stack[] = $context;
	}

	public static function pop(): ?AuditOperationContext
	{
		return array_pop(self::$stack);
	}

	public static function clear(): void
	{
		self::$stack = [];
	}

	public static function createOperationId(): string
	{
		return UuidGenerator::generateV4();
	}

	public static function runWithContext(AuditOperationContext $context, callable $callback): mixed
	{
		$current = self::current();
		if ($current !== null)
		{
			$context = $context->joinBoundary($current);
		}

		self::push($context);

		try
		{
			return $callback();
		}
		finally
		{
			self::pop();
		}
	}

	public static function runWithContextIfEmpty(AuditOperationContext $context, callable $callback): mixed
	{
		if (self::current() !== null)
		{
			return $callback();
		}

		return self::runWithContext($context, $callback);
	}
}
