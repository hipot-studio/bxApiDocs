<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

use Bitrix\Main\Type\Contract\Arrayable;


final class Metrics implements Arrayable
{
	/**
	 * @var array<array-key, array{key: string, value: string, timestamp: float}>
	 */
	private array $buffer = [];

	public function add(string $key, string $value, float $timestamp): void
	{
		$this->buffer[] = [
			'key' => $key,
			'value' => $value,
			'timestamp' => $timestamp,
		];
	}

	/**
	 * @return array<array-key, array{key: string, value: string, timestamp: float}>
	 */
	public function toArray(): array
	{
		return $this->buffer;
	}
}
