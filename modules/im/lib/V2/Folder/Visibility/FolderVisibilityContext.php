<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Visibility;

use Bitrix\Im\V2\Folder\BaseFolder;
use Bitrix\Im\V2\Folder\FolderType;
use Bitrix\Im\V2\Folder\System\AllFolder;

/**
 * The access invariant of a hidden folder (ALG-01) answered for one owner. Built by
 * {@see FolderVisibilityResolver::createContext} and shared by every folder of one read, so a
 * folder publishes its visibility without reaching for a service.
 *
 * The two per-user facts are resolved on first use and kept for the life of the context: a read
 * that never publishes a folder does not walk the source registry at all.
 */
final class FolderVisibilityContext
{
	private ?bool $allFolderAvailable = null;

	/** @var array<string, string>|null */
	private ?array $sourceCodeBySection = null;

	/**
	 * @param \Closure(): bool $resolveAllFolderAvailable
	 * @param \Closure(): array<string, string> $resolveSourceCodeBySection recent section => stored
	 *        code of the source that keeps it inside "All"
	 */
	public function __construct(
		private readonly \Closure $resolveAllFolderAvailable,
		private readonly \Closure $resolveSourceCodeBySection,
	) {}

	/**
	 * Whether the folder may be hidden right now. Published as `canHide`.
	 */
	public function canHide(BaseFolder $folder): bool
	{
		if ($folder->isHiddenBySystem() || !$this->isAllFolderAvailable())
		{
			return false;
		}

		if ($folder->getCode() === AllFolder::CODE)
		{
			return false;
		}

		// A personal folder holds no section, and a showcase has no recent list of its own (Q-2):
		// hiding either takes no chats away.
		if ($folder->getType() === FolderType::Personal->value || !$folder->getDefinition()->supportsTail())
		{
			return true;
		}

		return $folder->canBeHiddenWithoutSource() || $this->findSourceCodeOf($folder) !== null;
	}

	/**
	 * The decision of the owner as the payload publishes it: while "All" is unavailable it stays in
	 * the row unpublished, so a folder is never both hidden and unreachable.
	 */
	public function publishesHiddenByUser(BaseFolder $folder): bool
	{
		return $folder->isHiddenByUser() && $this->isAllFolderAvailable();
	}

	/**
	 * Stored code of the source that keeps the section of the folder inside "All", or null when no
	 * source available to the owner feeds it.
	 */
	public function findSourceCodeOf(BaseFolder $folder): ?string
	{
		$section = $folder->getRecentSection();
		if ($section === null)
		{
			return null;
		}

		$this->sourceCodeBySection ??= ($this->resolveSourceCodeBySection)();

		return $this->sourceCodeBySection[$section] ?? null;
	}

	private function isAllFolderAvailable(): bool
	{
		return $this->allFolderAvailable ??= ($this->resolveAllFolderAvailable)();
	}
}
