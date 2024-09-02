<?php

namespace Bitrix\Sign\Result\Operation;

use Bitrix\Sign\Result\Base;

class MemberWebStatusResult extends Base
{
	public function __construct(
		public string $status
	)
	{
		parent::__construct();
	}
}