<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Visibility;

use Bitrix\Im\V2\Folder\BaseFolder;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\FolderCollection;
use Bitrix\Im\V2\Folder\System\AllFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;

/**
 * The access invariant of a hidden folder (ALG-01) and the two visibility fields the payload
 * publishes from it. Hiding a section must never take its chats away: a section may leave the
 * navigation only while the user still reaches its chats through the composition of "All". The
 * only exception is a folder hidden without a source ({@see BaseFolder::canBeHiddenWithoutSource}).
 *
 * The invariant is stated over recent sections and not over folder codes - the two do not map one
 * to one. "Tasks" and its composition source share the section `tasksTask` under different codes,
 * and the open-lines section has no source of its own at all.
 *
 * Reads no table: the folders and the stored composition arrive as arguments. The rule itself
 * lives in {@see FolderVisibilityContext}; this service builds a context per owner and never keeps
 * one, because the availability of "All" is a portal option read live
 * ({@see \Bitrix\Im\V2\Application\Features::isChatFolderAllAvailable}) and the service is a
 * singleton in the container.
 */
class FolderVisibilityResolver
{
	public function __construct(
		private readonly SystemFolderRegistry $systemFolderRegistry,
	) {}

	public function createContext(int $userId): FolderVisibilityContext
	{
		return new FolderVisibilityContext(
			fn (): bool => $this->isAllFolderAvailable($userId),
			fn (): array => $this->getSourceCodeBySection($userId),
		);
	}

	/**
	 * Gives every folder of one read the same context of its owner, which the folder publishes its
	 * visibility against ({@see BaseFolder::getPublishedVisibility}).
	 *
	 * @param iterable<Folder> $folders
	 */
	public function attachContext(iterable $folders, int $userId): void
	{
		$context = $this->createContext($userId);

		foreach ($folders as $folder)
		{
			if ($folder instanceof BaseFolder)
			{
				$folder->setVisibilityContext($context);
			}
		}
	}

	/**
	 * Whether the folder may be hidden right now (ALG-01). Published as `canHide`.
	 *
	 * The stored composition of "All" is no part of the question: a source switched off is switched
	 * back on by the hiding itself, and which source that is {@see self::resolveSourceToReturn}
	 * answers.
	 */
	public function canHide(BaseFolder $folder, int $userId): bool
	{
		return $this->createContext($userId)->canHide($folder);
	}

	/**
	 * Stored code of the source hiding the folder has to switch back on, or null when the
	 * composition already keeps the section reachable.
	 *
	 * @param string[] $storedSourceCodes composition stored for the user's "All", in stored codes
	 */
	public function resolveSourceToReturn(BaseFolder $folder, int $userId, array $storedSourceCodes): ?string
	{
		$context = $this->createContext($userId);
		if (!$context->canHide($folder))
		{
			return null;
		}

		$sourceCode = $context->findSourceCodeOf($folder);
		if ($sourceCode === null || in_array($sourceCode, $storedSourceCodes, true))
		{
			return null;
		}

		return $sourceCode;
	}

	/**
	 * Recent sections whose navigation folder the user has hidden: the source feeding such a
	 * section is temporarily mandatory and its item on the composition screen is shown locked.
	 *
	 * The collection is passed in rather than read here, so the rule stays free of the database
	 * and the caller keeps its single read of the folder list.
	 *
	 * @return string[] unique recent sections
	 */
	public function getSectionsLockedByHiddenFolders(int $userId, FolderCollection $folders): array
	{
		$context = $this->createContext($userId);

		$sections = [];
		foreach ($folders as $folder)
		{
			if ($folder->isHiddenBySystem() || !$context->publishesHiddenByUser($folder))
			{
				continue;
			}

			$section = $folder->getRecentSection();
			if ($section !== null)
			{
				$sections[$section] = true;
			}
		}

		return array_keys($sections);
	}

	/**
	 * The user's own decision as the payload publishes it: while "All" is unavailable the decision
	 * stays in the row but is never published, so a folder is never both hidden and unreachable.
	 */
	public function publishHiddenByUser(BaseFolder $folder, int $userId): bool
	{
		return $this->createContext($userId)->publishesHiddenByUser($folder);
	}

	/**
	 * Recent section => stored code of the source that keeps it inside "All", built from the
	 * canonical registry with the sources unavailable to this user dropped. Availability is asked
	 * per user and not per portal: {@see AllFolder::getPotentialRecentSections} deliberately asks
	 * the portal one, and hiding against it could leave the chats of the section unreachable.
	 *
	 * The registry order decides the winner when two sources answer for one section.
	 *
	 * @return array<string, string>
	 */
	private function getSourceCodeBySection(int $userId): array
	{
		$sourceCodeBySection = [];

		foreach (AllFolder::getDefaultDefinition() as $entry)
		{
			$member = $this->systemFolderRegistry->findByCode($entry->code)?->instantiate();
			if ($member === null || !$member->isAvailable($userId))
			{
				continue;
			}

			$section = $member->getRecentSection();
			if ($section !== null && !isset($sourceCodeBySection[$section]))
			{
				$sourceCodeBySection[$section] = $entry->code;
			}
		}

		return $sourceCodeBySection;
	}

	private function isAllFolderAvailable(int $userId): bool
	{
		$allFolder = $this->systemFolderRegistry->findByCode(AllFolder::CODE)?->instantiate();

		return $allFolder !== null && $allFolder->isAvailable($userId);
	}
}
