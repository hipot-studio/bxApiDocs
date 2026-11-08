<?php

namespace Bitrix\Main\Cli\Command\Main\Option;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Config\Option;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GetCommand extends AbstractFormattedCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:option:get')
			->setDescription('Get module option value')
			->addArgument(
				'module',
				InputArgument::REQUIRED,
				'Module identifier',
			)
			->addArgument(
				'name',
				InputArgument::REQUIRED,
				'Option name',
			)
			->addOption(
				'site',
				null,
				InputOption::VALUE_REQUIRED,
				'Site identifier',
			)
			->addOption(
				'raw',
				null,
				InputOption::VALUE_NONE,
				'Return stored value only',
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		$moduleId = (string)$input->getArgument('module');
		$name = (string)$input->getArgument('name');
		$siteId = $input->getOption('site');
		$siteId = is_string($siteId) ? $siteId : null;
		$raw = $input->getOption('raw') === true;
		$value = $this->getOptionValue($moduleId, $name, $siteId, $raw);

		return $this->renderSuccess(
			$output,
			[
				'module' => $moduleId,
				'name' => $name,
				'site' => $siteId,
				'raw' => $raw,
				'exists' => $value !== null,
				'value' => $value,
			],
			columns: [
				'module' => 'Module',
				'name' => 'Name',
				'site' => 'Site',
				'raw' => 'Stored only',
				'exists' => 'Exists',
				'value' => 'Value',
			],
		);
	}

	protected function getOptionValue(string $moduleId, string $name, ?string $siteId, bool $raw): ?string
	{
		return $raw
			? Option::getRealValue($moduleId, $name, $siteId ?? '')
			: Option::get($moduleId, $name, null, $siteId ?? '')
		;
	}
}
