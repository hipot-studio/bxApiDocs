<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\Main\Result;

class BlockSettingsResult extends Result
{
	public function __construct(
		public readonly BlockTypeDetail $blockDetail,
	)
	{
		parent::__construct();
	}
}