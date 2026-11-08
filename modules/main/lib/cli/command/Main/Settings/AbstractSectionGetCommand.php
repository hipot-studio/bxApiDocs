<?php

namespace Bitrix\Main\Cli\Command\Main\Settings;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Config\Configuration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractSectionGetCommand extends AbstractFormattedCommand
{
	protected function configure(): void
	{
		$this
			->setName($this->getCommandName())
			->setDescription($this->getCommandDescription())
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		$section = $this->getSectionName();
		$value = Configuration::getValue($section);

		return $this->renderSuccess(
			$output,
			[
				'section' => $section,
				'exists' => $value !== null,
				'value' => $value,
			],
			columns: [
				'section' => 'Section',
				'exists' => 'Exists',
				'value' => 'Value',
			],
		);
	}

	abstract protected function getCommandName(): string;

	abstract protected function getSectionName(): string;

	protected function getCommandDescription(): string
	{
		return 'Get ' . $this->getSectionName() . ' section from .settings.php';
	}
}
