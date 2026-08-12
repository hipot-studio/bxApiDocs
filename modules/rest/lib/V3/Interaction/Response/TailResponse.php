<?php

namespace Bitrix\Rest\V3\Interaction\Response;

use Bitrix\Rest\V3\Dto\DtoCollection;

class TailResponse extends ListResponse
{
	public CursorResponseDto $cursor;

	public function __construct(
		DtoCollection $items,
		public bool $hasMore,
		?string $cursorField = null,
		?string $cursorValue = null,
	)
	{
		parent::__construct($items);
		$this->cursor = new CursorResponseDto();
		$this->cursor->field = $cursorField;
		$this->cursor->value = $cursorValue;
	}
}
