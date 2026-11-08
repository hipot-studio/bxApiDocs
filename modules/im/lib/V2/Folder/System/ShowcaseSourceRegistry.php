<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

/**
 * Showcase sources of the composition screen (TPL-02): folders shown as a locked item to
 * tell the user such chats exist, while they never become a source of {@see AllFolder}.
 *
 * The registry knows nothing about the user or about availability - both are the reading
 * service's job ({@see \Bitrix\Im\V2\Folder\Source\FolderSourceService}). It stays separate
 * from the canonical registry so that a walk over {@see AllFolder::getDefaultDefinition()}
 * cannot see a showcase code.
 */
final class ShowcaseSourceRegistry
{
	/** @var ShowcaseSourceEntry[]|null lazy-built canonical list */
	private ?array $entries = null;

	/**
	 * @return ShowcaseSourceEntry[]
	 */
	public function getAll(): array
	{
		if ($this->entries !== null)
		{
			return $this->entries;
		}

		$this->entries = [
			new ShowcaseSourceEntry(OpenLineFolder::CODE, afterCode: null),
		];

		return $this->entries;
	}
}
