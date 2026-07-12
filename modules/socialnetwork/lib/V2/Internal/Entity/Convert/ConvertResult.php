<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Convert;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Item\Workgroup;

class ConvertResult extends Result
{
	public function getGroupAfter(): ?Workgroup
	{
		return $this->data['groupAfter'] ?? null;
	}

	public function setGroupAfter(Workgroup $groupAfter): self
	{
		$this->data['groupAfter'] = $groupAfter;

		return $this;
	}
}
