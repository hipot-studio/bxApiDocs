<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Source;

use Bitrix\Im\V2\Folder\FolderCollection;

/**
 * Everything the composition screen is drawn from: the items in the order the server fixed
 * and the source folders they are bound to, one per item, in the same order.
 *
 * The REST shape is flat on purpose - two named collections instead of one entity, so it is
 * returned as is and never wrapped by an entity name. The folders keep the ordinary REST
 * shape of a folder: the screen reads their title and description, and no truncated format
 * of our own would survive the next field added to a folder.
 */
final readonly class SourceList
{
	/**
	 * @param SourceItem[] $items
	 * @param FolderCollection $folders source folders of the items
	 */
	public function __construct(
		public array $items,
		public FolderCollection $folders,
	) {}

	public function toRestFormat(): array
	{
		$sources = [];
		foreach ($this->items as $item)
		{
			$sources[] = $item->toRestFormat();
		}

		return [
			'sources' => $sources,
			'folders' => $this->folders->toRestFormat(),
		];
	}
}
