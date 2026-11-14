<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Sort;

use Bitrix\Im\V2\Common\Normalizer;

/**
 * Input of the "Folder list" screen save (API-02): the order of the visible folders as the client
 * knew it, the folders coming back to the navigation and the folders leaving it.
 *
 * Both sets default to the empty one, and the default carries meaning: the form transport tells an
 * absent key from an empty array nowhere, so "no set given" and "an empty set" are one and the same
 * request - the one `im.v2.Folder.sort` sends when it reorders and nothing else.
 *
 * The order may carry the ids of the folders coming back as well: a folder returned by dragging it
 * into the list arrives with the position it was dropped at, while one returned by a checkbox is
 * missing from the order and keeps the slot it held.
 *
 * Carries no rule of its own. Whether the sets are disjoint, whose folders they name and which of
 * them may be hidden at all is decided by {@see FolderSortService::saveFolderList}.
 *
 * The action of the controller builds it by hand instead of through parameter validation, so
 * {@see self::fromArray} is the one place the ids of a request are checked at all.
 */
final class SaveListFields
{
	/**
	 * @param int[] $folderIds
	 * @param int[] $showFolderIds
	 * @param int[] $hideFolderIds
	 */
	public function __construct(
		public readonly array $folderIds,
		public readonly array $showFolderIds = [],
		public readonly array $hideFolderIds = [],
	) {}

	public static function fromArray(array $data): self
	{
		return new self(
			folderIds: self::normalizeIds($data['folderIds'] ?? null),
			showFolderIds: self::normalizeIds($data['showFolderIds'] ?? null),
			hideFolderIds: self::normalizeIds($data['hideFolderIds'] ?? null),
		);
	}

	/**
	 * @return int[]
	 */
	private static function normalizeIds(mixed $values): array
	{
		return Normalizer::toUniquePositiveIntegers(is_array($values) ? $values : []);
	}
}
