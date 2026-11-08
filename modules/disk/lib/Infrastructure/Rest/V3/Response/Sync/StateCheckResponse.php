<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * The answer of an addressed check: one verdict per object, and - in the mode over an area alone -
 * whether the area goes on and the position that continues it.
 *
 * It extends the canonical ListResponse so the answer is serialised by the same path as every other
 * V3 listing. The two fields of the mode over an area are absent from the answer of the mode over a
 * list rather than present and empty: a list is the whole page there is, and a client handed
 * hasMore = false with no position beside it would have to guess whether the walk it never started
 * had ended.
 */
final class StateCheckResponse extends ListResponse
{
	/** Both are null exactly when the answer is the one of the mode over a list. */
	public ?bool $hasMore = null;

	public ?string $nextCheckToken = null;

	private function __construct(DtoCollection $items)
	{
		parent::__construct($items);
	}

	/**
	 * @param DtoCollection $items one verdict per object asked about, in the order they were asked
	 *        about, CheckItemDto
	 */
	public static function ofList(DtoCollection $items): self
	{
		return new self($items);
	}

	/**
	 * @param DtoCollection $items the objects of the page, all of them visible, CheckItemDto
	 * @param bool $hasMore whether the area goes on; decided by the walk and never by the size of a
	 *        page, which links collapsing inside it may shorten
	 * @param string $nextCheckToken the position the next page is asked for by, answered on the last
	 *        page as well
	 */
	public static function ofScope(DtoCollection $items, bool $hasMore, string $nextCheckToken): self
	{
		$response = new self($items);
		$response->hasMore = $hasMore;
		$response->nextCheckToken = $nextCheckToken;

		return $response;
	}

	public function toArray(): array
	{
		$result = parent::toArray();

		if ($this->nextCheckToken === null)
		{
			unset($result['hasMore'], $result['nextCheckToken']);
		}

		return $result;
	}
}
