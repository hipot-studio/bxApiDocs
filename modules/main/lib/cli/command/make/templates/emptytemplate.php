<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class EmptyTemplate implements Template
{
	public function getContent(): string
	{
		return '';
	}
}
