<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

use Bitrix\Main\Type\Contract\Arrayable;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;


final class Logs implements LoggerInterface, Arrayable
{
	use LoggerTrait;

	/**
	 * @var array<array-key, array{level: string, message: string, context: array<array-key, mixed>|null, timestamp: float}>
	 */
	private array $buffer = [];

	public function toArray(): array
	{
		return $this->buffer;
	}

	/**
	 * @param mixed $level
	 * @param string|Stringable $message
	 * @param array<array-key, mixed> $context
	 */
	public function log($level, $message, array $context = []): void
	{
		$logLevel = $this->mapPsrLevel($level);
		$this->add($logLevel->value, (string)$message, $context);
	}

	private function mapPsrLevel(string $level): LogLevel
	{
		return match ($level) {
			'emergency' => LogLevel::Emergency,
			'alert' => LogLevel::Alert,
			'critical' => LogLevel::Critical,
			'error' => LogLevel::Error,
			'warning' => LogLevel::Warning,
			'info' => LogLevel::Info,
			'debug' => LogLevel::Debug,
			default => LogLevel::Notice,
		};
	}

	private function add(string $level, string $message, array|null $context = null, float|null $timestamp = null): void
	{
		$this->buffer[] = [
			'level' => $level,
			'message' => $message,
			'context' => $context ?? null,
			'timestamp' => $timestamp ?? Timestamp::now()->getValue(),
		];
	}
}
