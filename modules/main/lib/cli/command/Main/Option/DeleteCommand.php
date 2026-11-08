<?php

namespace Bitrix\Main\Cli\Command\Main\Option;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Config\Option;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteCommand extends AbstractFormattedCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:option:delete')
			->setDescription('Delete stored module option value')
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
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		$moduleId = (string)$input->getArgument('module');
		$name = (string)$input->getArgument('name');
		$siteId = $input->getOption('site');
		$siteId = is_string($siteId) ? $siteId : null;
		$optionSiteId = $siteId ?? '';
		$valueBefore = $this->getRealOptionValue($moduleId, $name, $optionSiteId);
		if ($valueBefore !== null)
		{
			$this->deleteOptionValue($moduleId, $name, $optionSiteId);
		}
		$changed = $valueBefore !== null && $this->getRealOptionValue($moduleId, $name, $optionSiteId) === null;

		return $this->renderSuccess(
			$output,
			[
				'module' => $moduleId,
				'name' => $name,
				'site' => $siteId,
			],
			['changed' => $changed],
			[
				'module' => 'Module',
				'name' => 'Name',
				'site' => 'Site',
			],
		);
	}

	protected function getRealOptionValue(string $moduleId, string $name, string $siteId): ?string
	{
		return Option::getRealValue($moduleId, $name, $siteId);
	}

	protected function deleteOptionValue(string $moduleId, string $name, string $siteId): void
	{
		Option::delete($moduleId, ['name' => $name, 'site_id' => $siteId]);
	}
}
