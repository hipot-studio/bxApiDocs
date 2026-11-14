<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use InvalidArgumentException;

/** The editor's complete current set of legal document bindings. */
final readonly class FormBindings
{
	public array $items;

	public function __construct(array $items, public ?int $expectedSourceRevision)
	{
		if (!array_is_list($items) || ($expectedSourceRevision !== null
			&& ($expectedSourceRevision <= 0 || $expectedSourceRevision > 2147483647)))
		{
			throw new InvalidArgumentException('Invalid legal document bindings.');
		}
		$normalized = [];
		$documents = [];
		foreach ($items as $position => $item)
		{
			if (!is_array($item) || array_diff(array_keys($item),
				['documentId', 'versionId', 'checked', 'required', 'order', 'notice']) !== []
				|| !self::id($item['documentId'] ?? null) || !self::id($item['versionId'] ?? null)
				|| isset($documents[$item['documentId']])
				|| (isset($item['checked']) && !is_bool($item['checked']))
				|| (isset($item['required']) && !is_bool($item['required']))
				|| ($item['order'] ?? null) !== $position || !is_string($item['notice'] ?? null))
			{
				throw new InvalidArgumentException('Invalid legal document bindings.');
			}
			$documents[$item['documentId']] = true;
			$normalized[] = [
				'documentId' => $item['documentId'], 'versionId' => $item['versionId'],
				'checked' => $item['checked'] ?? false, 'required' => $item['required'] ?? true,
				'order' => $position, 'notice' => $item['notice'],
			];
		}
		$this->items = $normalized;
	}

	private static function id(mixed $value): bool
	{
		return is_int($value) && $value > 0 && $value <= 2147483647;
	}
}
