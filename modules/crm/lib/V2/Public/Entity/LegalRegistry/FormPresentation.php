<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use DateTimeImmutable;
use InvalidArgumentException;

/** A signed public display, without answers or submission authority. */
final readonly class FormPresentation
{
	public array $items;

	public function __construct(
		public string $presentationToken,
		public int $sourceRevision,
		array $items,
		public DateTimeImmutable $presentedAt,
	)
	{
		if ($presentationToken === '' || strlen($presentationToken) > 2097152
			|| $sourceRevision <= 0 || $sourceRevision > 2147483647
			|| !array_is_list($items) || count($items) > 100)
		{
			throw new InvalidArgumentException('Invalid form presentation.');
		}
		$snapshot = [];
		$documents = [];
		foreach ($items as $position => $item)
		{
			$keys = ['documentId', 'versionId', 'bindingRevision', 'label', 'text', 'textHash',
				'checked', 'required', 'notice', 'order'];
			if (!is_array($item) || count($item) !== count($keys)
				|| array_diff($keys, array_keys($item)) !== [])
			{
				throw new InvalidArgumentException('Invalid form presentation item.');
			}
			foreach (['documentId', 'versionId', 'bindingRevision'] as $key)
			{
				if (!is_int($item[$key]) || $item[$key] <= 0 || $item[$key] > 2147483647)
				{
					throw new InvalidArgumentException('Invalid form presentation identity.');
				}
			}
			if (isset($documents[$item['documentId']]) || !is_bool($item['checked'])
				|| !is_bool($item['required']) || $item['order'] !== $position
				|| !is_string($item['label']) || !is_string($item['text']) || !is_string($item['notice'])
				|| !is_string($item['textHash']) || !hash_equals(hash('sha256', $item['text']), $item['textHash']))
			{
				throw new InvalidArgumentException('Invalid form presentation content.');
			}
			$documents[$item['documentId']] = true;
			$row = [];
			foreach ($keys as $key) { $row[$key] = $item[$key]; }
			$snapshot[] = $row;
		}
		$this->items = $snapshot;
	}

	/** Returns the anonymous display envelope. */
	public function toArray(): array
	{
		return ['presentationToken' => $this->presentationToken, 'sourceRevision' => $this->sourceRevision,
			'items' => $this->items, 'presentedAt' => $this->presentedAt->format('Y-m-d\TH:i:s.uP')];
	}
}
