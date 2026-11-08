<?php

namespace Bitrix\Main\Cli\Command\Main\Option;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Config\Option;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends AbstractFormattedCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:option:list')
			->setDescription('List stored module options')
			->addArgument(
				'module',
				InputArgument::REQUIRED,
				'Module identifier',
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
		$siteId = $input->getOption('site');
		$siteId = is_string($siteId) ? $siteId : null;
		$raw = $input->getOption('raw') === true;
		$options = $this->getModuleOptions($moduleId, $siteId ?? '');
		ksort($options);
		$data = [];

		foreach (array_keys($options) as $name)
		{
			$name = (string)$name;
			$value = $this->getOptionValue($moduleId, $name, $siteId, $raw);
			$data[] = [
				'module' => $moduleId,
				'name' => $name,
				'site' => $siteId,
				'raw' => $raw,
				'exists' => $value !== null,
				'value' => $value,
			];
		}

		return $this->renderSuccess(
			$output,
			$data,
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

	protected function getModuleOptions(string $moduleId, string $siteId): array
	{
		return Option::getForModule($moduleId, $siteId);
	}

	protected function getOptionValue(string $moduleId, string $name, ?string $siteId, bool $raw): ?string
	{
		return $raw
			? Option::getRealValue($moduleId, $name, $siteId ?? '')
			: Option::get($moduleId, $name, null, $siteId ?? '')
		;
	}
}
