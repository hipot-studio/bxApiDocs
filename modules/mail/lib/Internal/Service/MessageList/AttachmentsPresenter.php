<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Internal\Service\Attachment\FileIcon;

/**
 * Attachment summary of a message in three states: not loaded yet, loaded and empty, loaded with
 * files. The row draws a different cell for each.
 */
class AttachmentsPresenter
{
	/**
	 * @return array{count: int, icons: array<int, array>}|null null while the summary is not ready
	 */
	public function present(array $item): ?array
	{
		$stack = $item['__attachments_stack'] ?? null;
		if (!is_array($stack))
		{
			return null;
		}

		$icons = is_array($stack['icons'] ?? null) ? $stack['icons'] : [];

		return [
			'count' => (int)($stack['count'] ?? 0),
			'icons' => array_map($this->presentIcon(...), array_values($icons)),
		];
	}

	/** The message reports its files even while their summary has not been loaded. */
	public function hasAttachments(array $item): bool
	{
		$options = is_array($item['OPTIONS'] ?? null) ? $item['OPTIONS'] : [];

		return (int)($options['attachments'] ?? 0) > 0 || (int)($item['ATTACHMENTS'] ?? 0) > 0;
	}

	private function presentIcon(mixed $icon): array
	{
		$icon = is_array($icon) ? $icon : [];

		return [
			'name' => (string)($icon['name'] ?? ''),
			'size' => (string)($icon['size'] ?? ''),
			'url' => $icon['url'] ?? null,
			'viewerAttrs' => is_array($icon['viewerAttrs'] ?? null) ? $icon['viewerAttrs'] : null,
			'icon' => FileIcon::resolve((string)($icon['extension'] ?? '')),
		];
	}
}
