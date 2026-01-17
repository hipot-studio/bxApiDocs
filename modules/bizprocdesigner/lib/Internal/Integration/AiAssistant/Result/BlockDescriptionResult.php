<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeCollection;
use Bitrix\Main\Result;

class BlockDescriptionResult extends Result
{
	public function __construct(
		public readonly BlockTypeCollection $blocks,
	)
	{
		parent::__construct();
	}
}