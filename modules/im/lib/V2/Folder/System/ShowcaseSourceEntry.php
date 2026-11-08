<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

/**
 * One record of the showcase source registry (TPL-02): a folder the composition screen
 * shows as a permanently locked item, never a real source of {@see AllFolder}.
 *
 * The record lives outside {@see AllFolder::getDefaultDefinition()} on purpose - a showcase
 * code must reach neither the stored composition, nor the initial configuration, nor the
 * eligibility rule ({@see AllFolder::getPotentialRecentSections}).
 *
 * The record carries no text and no icon: the item's title and subtitle come from the
 * folder object it points at, and the locked state is a property of the registry itself.
 */
final class ShowcaseSourceEntry
{
	/**
	 * @param string $folderCode stored folder code (e.g. {@see OpenLineFolder::CODE}); the item
	 *        published for it carries the folder's own rest code
	 * @param string|null $afterCode source code of the canonical registry the item is placed
	 *        after; null puts the item at the tail of the list
	 */
	public function __construct(
		public readonly string $folderCode,
		public readonly ?string $afterCode,
	) {}
}
