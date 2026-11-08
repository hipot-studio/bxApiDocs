<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

/**
 * The saved screen filter in the shape the loader expects. The folder is kept apart from the filter
 * because screen modes drop it from there, while the row still needs it for the slider analytics and
 * for the permanent deletion item.
 */
final class FilterState
{
	/**
	 * @param bool $applied The reader narrowed the list themselves rather than just walked into a
	 *                      folder. An empty list says one word about a folder out of letters and
	 *                      another about a search that found none, and only the server knows which of
	 *                      the two this is: the saved state is read anew on every page.
	 */
	public function __construct(
		public readonly array $filter,
		public readonly string $currentDir,
		public readonly bool $applied = false,
	)
	{
	}
}
