<?php

namespace Bitrix\Sign\Item\Api\Document\Placeholder;

use Bitrix\Sign\Contract;
use InvalidArgumentException;

class ListRequest implements Contract\Item
{
	public string $documentUid;

	public function __construct(string $documentUid)
	{
		if (empty($documentUid))
		{
			throw new InvalidArgumentException("Document uid cannot be empty.");
		}

		$this->documentUid = $documentUid;
	}
}