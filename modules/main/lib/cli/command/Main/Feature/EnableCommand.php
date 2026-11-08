<?php

namespace Bitrix\Main\Cli\Command\Main\Feature;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Config\Feature;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnableCommand extends AbstractFormattedCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:feature:enable')
			->setDescription('Enable feature flag')
			->addArgument(
				'flag-class',
				InputArgument::REQUIRED,
				'Feature flag class',
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		$flagClass = (string)$input->getArgument('flag-class');

		$this->enableFeature($flagClass);

		return $this->renderSuccess(
			$output,
			['flagClass' => $flagClass],
			[],
			[
				'flagClass' => 'Feature flag class',
			],
		);
	}

	protected function enableFeature(string $flagClass): void
	{
		Feature::enable($flagClass);
	}
}
