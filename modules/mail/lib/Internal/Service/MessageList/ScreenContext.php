<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

/**
 * Everything a page of the list depends on and cannot read for itself. The screen fills it from its
 * own parameters, the controller rebuilds the same values from the request.
 */
final class ScreenContext
{
	/** Row action handlers and the binding extension address the client manager by this name. */
	public const LIST_MANAGER_ID = 'mail-client-list-manager';

	public const SECTION_FAVORITES = 'favorites';

	/**
	 * @param int[] $mailboxIds Mailboxes of the user; read in the all mail mode only.
	 * @param array $filterFields Filter fields as the screen declares them. Reading the filter rewrites
	 *                            the saved settings from the request, so an empty declaration wipes them.
	 * @param array $filterPresets Filter presets as the screen builds them.
	 * @param array $directoryMenuItems Folder tree of the "move to folder" submenu. Left empty here:
	 *                                  the tree is one per mailbox and the client hangs its own.
	 * @param array|null $savedFilter The filter a screen has already read. Null means nobody read it
	 *                                yet and the page reads it itself.
	 */
	public function __construct(
		public readonly int $userId,
		public readonly int $mailboxId,
		public readonly string $gridId,
		public readonly string $filterId,
		public readonly string $mailboxEmail = '',
		public readonly int $page = 1,
		public readonly array $mailboxIds = [],
		public readonly bool $isAllMailMode = false,
		public readonly ?string $listSection = null,
		public readonly bool $listImprovementsEnabled = false,
		public readonly bool $labelsEnabled = false,
		public readonly bool $crmActivityPermission = false,
		public readonly array $filterFields = [],
		public readonly array $filterPresets = [],
		public readonly array $directoryMenuItems = [],
		public readonly ?array $savedFilter = null,
	)
	{
	}

	/**
	 * @return int[]
	 */
	public function getMailboxIdsForFilter(): array
	{
		return $this->isAllMailMode ? $this->mailboxIds : [$this->mailboxId];
	}

	public function isFavoritesSection(): bool
	{
		return $this->listImprovementsEnabled && $this->listSection === self::SECTION_FAVORITES;
	}
}
