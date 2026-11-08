<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Item\Collection;

/**
 * @extends Collection<AvailableProcess>
 */
final class AvailableProcessCollection extends Collection implements \JsonSerializable
{
	public function findById(int $id): ?AvailableProcess
	{
		if ($id < 1)
		{
			return null;
		}

		return $this->findByRule(static fn(AvailableProcess $process): bool => $id === $process->id);
	}

	public function jsonSerialize(): array
	{
		return array_map(static fn(AvailableProcess $process): array => $process->toArray(), $this->toArray());
	}

	protected function getItemClassName(): string
	{
		return AvailableProcess::class;
	}
}
