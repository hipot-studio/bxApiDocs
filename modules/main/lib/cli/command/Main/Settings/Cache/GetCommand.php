<?php

namespace Bitrix\Main\Cli\Command\Main\Settings\Cache;

use Bitrix\Main\Cli\Command\Main\Settings\AbstractSectionGetCommand;
use Bitrix\Main\Cli\Command\Main\Settings\SettingsConfigurator;
use Bitrix\Main\Cli\Output\OutputFormatter;

final class GetCommand extends AbstractSectionGetCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function getCommandName(): string
	{
		return 'main:settings:cache-get';
	}

	protected function getSectionName(): string
	{
		return SettingsConfigurator::SECTION_CACHE;
	}
}
