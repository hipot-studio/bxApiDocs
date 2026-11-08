<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Definition;

/**
 * Folder without a standard recent list: the open-channel showcase (tail not
 * supported) and the compatibility stub for an unknown or unsupported folder
 * type. Carries only its recent section for display and yields no selection.
 */
final class UnsupportedDefinition implements FolderDefinition
{
	public function __construct(
		private readonly ?string $recentSection = null,
	) {}

	public function toRestFormat(): array
	{
		return ['recentSection' => $this->recentSection];
	}

	public function toSelection(): Selection
	{
		return new Selection();
	}

	public function supportsTail(): bool
	{
		return false;
	}

	public function containsChat(int $chatId): bool
	{
		return false;
	}
}
