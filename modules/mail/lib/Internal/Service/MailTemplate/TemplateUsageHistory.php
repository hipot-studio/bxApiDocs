<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MailTemplate;

use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateReference;

class TemplateUsageHistory
{
	public const OPTION_CATEGORY = 'mail';
	public const HISTORY_OPTION_NAME = 'template_usage_history';

	private const MAX_ITEMS = 5;

	/**
	 * @return list<TemplateReference>
	 */
	public function getReferences(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		return array_map(
			static fn(array $item): TemplateReference => $item['reference'],
			$this->decodeHistory($this->readHistoryOption($userId)),
		);
	}

	public function recordUsage(
		int $userId,
		TemplateReference $reference,
		?int $time = null,
	): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$history = array_values(array_filter(
			$this->decodeHistory($this->readHistoryOption($userId)),
			static fn(array $item): bool => !$item['reference']->equals($reference),
		));
		array_unshift($history, [
			'reference' => $reference,
			'time' => $time ?? time(),
		]);

		$this->writeHistoryOption(
			$userId,
			$this->encodeHistory(array_slice($history, 0, self::MAX_ITEMS)),
		);
	}

	/**
	 * Removes only references that a catalog has explicitly confirmed as unavailable.
	 *
	 * @param TemplateReference[] $references
	 * @return int Number of removed history entries.
	 */
	public function removeUnavailable(int $userId, array $references): int
	{
		if ($userId <= 0 || empty($references))
		{
			return 0;
		}

		$references = array_values(array_filter(
			$references,
			static fn(mixed $reference): bool => $reference instanceof TemplateReference,
		));
		if (empty($references))
		{
			return 0;
		}

		$history = $this->decodeHistory($this->readHistoryOption($userId));
		$kept = [];
		$removed = 0;
		foreach ($history as $item)
		{
			if ($this->containsReference($references, $item['reference']))
			{
				++$removed;
				continue;
			}

			$kept[] = $item;
		}

		if ($removed > 0)
		{
			$this->writeHistoryOption($userId, $this->encodeHistory($kept));
		}

		return $removed;
	}

	protected function readHistoryOption(int $userId): mixed
	{
		return \CUserOptions::GetOption(
			self::OPTION_CATEGORY,
			self::HISTORY_OPTION_NAME,
			[],
			$userId,
		);
	}

	/**
	 * @param list<array{source: string, id: int, time: int}> $history
	 */
	protected function writeHistoryOption(int $userId, array $history): void
	{
		\CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::HISTORY_OPTION_NAME,
			$history,
			false,
			$userId,
		);
	}

	/**
	 * @return list<array{reference: TemplateReference, time: int}>
	 */
	private function decodeHistory(mixed $rawHistory): array
	{
		if (!is_array($rawHistory))
		{
			return [];
		}

		$history = [];
		foreach ($rawHistory as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$source = $rawItem['source'] ?? null;
			$id = $rawItem['id'] ?? null;
			$time = $rawItem['time'] ?? null;
			if (!is_string($source) || trim($source) === '' || !is_numeric($id) || (int)$id <= 0)
			{
				continue;
			}

			$reference = new TemplateReference($source, (int)$id);
			if ($this->containsDecodedReference($history, $reference))
			{
				continue;
			}

			$history[] = [
				'reference' => $reference,
				'time' => is_numeric($time) ? (int)$time : 0,
			];
			if (count($history) === self::MAX_ITEMS)
			{
				break;
			}
		}

		return $history;
	}

	/**
	 * @param list<array{reference: TemplateReference, time: int}> $history
	 * @return list<array{source: string, id: int, time: int}>
	 */
	private function encodeHistory(array $history): array
	{
		return array_map(
			static fn(array $item): array => [
				'source' => $item['reference']->getSource(),
				'id' => $item['reference']->getId(),
				'time' => $item['time'],
			],
			$history,
		);
	}

	/**
	 * @param TemplateReference[] $references
	 */
	private function containsReference(array $references, TemplateReference $expected): bool
	{
		foreach ($references as $reference)
		{
			if ($reference->equals($expected))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{reference: TemplateReference, time: int}> $history
	 */
	private function containsDecodedReference(array $history, TemplateReference $expected): bool
	{
		foreach ($history as $item)
		{
			if ($item['reference']->equals($expected))
			{
				return true;
			}
		}

		return false;
	}
}
