<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Query;

use Bitrix\Im\V2\Folder\Definition\Selection;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\System\SystemFolder;

/**
 * Search scope of a folder: the one place the search path raises a folder by id, checks
 * the owner and expands the composition into a {@see Selection}. Availability and
 * expansion are the same answer, so they cannot diverge - the caller asks twice (its own
 * availability gate and the query build) and pays for the folder only once.
 *
 * The composition is taken as its definition spells it, without
 * {@see Selection::rootFramed()}: in folder mode the nesting frame belongs to a branch,
 * exactly as it does on the list side.
 *
 * The definition expanded is the one the folder is searched by
 * ({@see \Bitrix\Im\V2\Folder\Folder::getSearchDefinition()}). For "All" that is the potential
 * composition - every source available to the owner - rather than the stored one, so a chat of a
 * source the owner switched off is still found here while the list no longer shows it.
 */
final class FolderSearchScope
{
	private ?string $resolvedKey = null;
	private ?Selection $selection = null;

	public function __construct(
		private readonly FolderProvider $folderProvider,
	) {}

	/**
	 * Returns the expanded composition of the folder, or null when the scope is
	 * unavailable: no such folder, a folder of another user, a system folder switched off
	 * for this user, or a definition that expands into nothing. All four answer the same,
	 * and the caller turns any of them into an empty search result.
	 *
	 * @param int $folderId
	 * @param int $userId
	 * @return Selection|null
	 */
	public function resolve(int $folderId, int $userId): ?Selection
	{
		$key = $folderId . ':' . $userId;

		// keyed rather than plain: a provider cloned by withContextUser() shares this object
		if ($this->resolvedKey !== $key)
		{
			$this->resolvedKey = $key;
			$this->selection = $this->expand($folderId, $userId);
		}

		return $this->selection;
	}

	private function expand(int $folderId, int $userId): ?Selection
	{
		$folder = $this->folderProvider->getById($folderId);
		if ($folder === null)
		{
			return null;
		}

		if ($folder->getUserId() !== $userId)
		{
			return null;
		}

		if ($folder instanceof SystemFolder && !$folder->isAvailable($userId))
		{
			return null;
		}

		$selection = $folder->getSearchDefinition()->toSelection();

		return $this->expandsIntoNothing($selection) ? null : $selection;
	}

	/**
	 * Emptiness of the composition, not of the branch list: a personal folder holding no chats
	 * still expands into one branch, and counting branches would call such a scope available and
	 * pay for the whole search. {@see Selection::isEmpty()} keeps its own meaning for the list
	 * side. Only an explicit chat list can be empty - a section branch carries a type condition.
	 */
	private function expandsIntoNothing(Selection $selection): bool
	{
		foreach ($selection->getBranches() as $branch)
		{
			if ($branch->chatIds !== [])
			{
				return false;
			}
		}

		return true;
	}
}
