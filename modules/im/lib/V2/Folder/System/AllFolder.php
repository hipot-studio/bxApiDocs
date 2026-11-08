<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Folder\Definition\CompositeDefinition;
use Bitrix\Im\V2\Folder\Definition\FolderDefinition;

/**
 * "All" - composite folder that unions its member folders. Its composition is
 * the list of member-folder codes stored in b_im_folder_source; the value
 * object {@see CompositeDefinition} carries the codes and the branch expansion
 * is resolved later through the system-folder registry.
 *
 * {@see AllFolder::getDefaultDefinition} is the canonical registry of sources
 * written once at bootstrap; the per-instance {@see AllFolder::$sourceCodes} is
 * the composition actually stored for this user, hydrated by
 * {@see \Bitrix\Im\V2\Folder\FolderProvider}.
 */
final class AllFolder extends SystemFolder
{
	public const CODE = 'all';

	/** Availability of every source is a portal-wide check, so the potential composition has no owner. */
	private const PORTAL_SCOPE_USER_ID = 0;

	/** @var string[]|null preloaded source folder codes - populated by {@see \Bitrix\Im\V2\Folder\FolderProvider} batch-load. */
	private ?array $sourceCodes = null;

	public function getCode(): string
	{
		return self::CODE;
	}

	/**
	 * "All" comes first for every user. For a user who never went through bootstrap
	 * this default is the only mechanism that puts it first - the other system folders
	 * default to 1.. and land after it in the same batch insert. An existing user gets
	 * the same result through
	 * {@see \Bitrix\Im\V2\Folder\FolderBootstrapper::insertSystemFolderAtPosition}.
	 */
	public function getDefaultPosition(): int
	{
		return 0;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_ALL';
	}

	public function getDescriptionLangKey(): ?string
	{
		return 'IM_FOLDER_SYSTEM_ALL_DESCRIPTION';
	}

	public function isAvailable(int $userId): bool
	{
		return Features::isChatFolderAllAvailable();
	}

	/**
	 * Internal setter used by {@see \Bitrix\Im\V2\Folder\FolderProvider} batch-load.
	 * Codes are normalized to the canonical registry order
	 * ({@see self::getDefaultDefinition}); unknown codes are dropped.
	 *
	 * @param string[] $sourceCodes member folder codes stored in b_im_folder_source
	 */
	public function setSourceCodes(array $sourceCodes): void
	{
		$enabled = array_fill_keys(array_map('strval', $sourceCodes), true);
		$ordered = [];
		foreach (self::getDefaultDefinition() as $source)
		{
			if (isset($enabled[$source->code]))
			{
				$ordered[] = $source->code;
			}
		}
		$this->sourceCodes = $ordered;
	}

	/**
	 * Canonical, ordered source registry (DTO-03). Order is fixed by the registry,
	 * not by stored data. "chat" is mandatory (kept by the registry, not by the
	 * presence of a row); every source ships in the initial configuration.
	 *
	 * @return SourceEntry[]
	 */
	public static function getDefaultDefinition(): array
	{
		return [
			new SourceEntry(ChatFolder::CODE, mandatory: true, inInitialConfig: true),
			new SourceEntry(TaskRootFolder::CODE, mandatory: false, inInitialConfig: true),
			new SourceEntry(CopilotFolder::CODE, mandatory: false, inInitialConfig: true),
			new SourceEntry(ChannelFolder::CODE, mandatory: false, inInitialConfig: true),
			new SourceEntry(CollabFolder::CODE, mandatory: false, inInitialConfig: true),
		];
	}

	/**
	 * Recent sections of the potential composition: every source of the canonical registry
	 * available on this portal, whatever the user actually enabled in b_im_folder_source. Reading
	 * the stored composition here would make disabling a source revoke the right to put such a chat
	 * into a personal folder, so it is deliberately not read
	 * ({@see \Bitrix\Im\V2\Folder\Eligibility\EligibleChatTypes}).
	 *
	 * @return string[]
	 */
	public static function getPotentialRecentSections(): array
	{
		$registry = new SystemFolderRegistry();
		$sections = [];

		foreach (self::getDefaultDefinition() as $source)
		{
			$member = $registry->findByCode($source->code)?->instantiate();
			if ($member === null || !$member->isAvailable(self::PORTAL_SCOPE_USER_ID))
			{
				continue;
			}

			$section = $member->getRecentSection();
			if ($section !== null)
			{
				$sections[$section] = true;
			}
		}

		return array_keys($sections);
	}

	/**
	 * The owner is handed to the composition so that sources unavailable to him are
	 * dropped from the selection. An instance with no owner (a registry prototype)
	 * resolves the stored composition unfiltered - see {@see CompositeDefinition}.
	 */
	public function getDefinition(): FolderDefinition
	{
		return new CompositeDefinition($this->sourceCodes ?? [], $this->getUserId());
	}

	/**
	 * The potential composition: every source of the canonical registry, enabled or not, narrowed
	 * to the ones available to the owner by {@see CompositeDefinition} itself. Without an owner the
	 * composition is empty - an ownerless {@see CompositeDefinition} would hand out the registry
	 * unfiltered, and there is nobody to search for anyway.
	 */
	public function getSearchDefinition(): FolderDefinition
	{
		$userId = $this->getUserId();
		if ($userId === null)
		{
			return new CompositeDefinition([]);
		}

		$codes = array_map(static fn (SourceEntry $source): string => $source->code, self::getDefaultDefinition());

		return new CompositeDefinition($codes, $userId);
	}
}
