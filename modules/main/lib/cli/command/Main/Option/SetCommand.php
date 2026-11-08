<?php

namespace Bitrix\Main\Cli\Command\Main\Option;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SetCommand extends AbstractFormattedCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:option:set')
			->setDescription('Set module option value')
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
			->addArgument(
				'value',
				InputArgument::REQUIRED,
				'Option value',
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
		try
		{
			$moduleId = (string)$input->getArgument('module');
			if ($moduleId === '' || !ModuleManager::isValidModule($moduleId))
			{
				throw new InvalidArgumentException(
					'Module identifier is invalid',
				);
			}
			$name = (string)$input->getArgument('name');
			$value = (string)$input->getArgument('value');
			$siteId = $input->getOption('site');
			$siteId = is_string($siteId) ? $siteId : null;
			$optionSiteId = $siteId ?? '';
			$valueBefore = $this->getRealOptionValue($moduleId, $name, $optionSiteId);
			if ($valueBefore !== $value)
			{
				$this->setOptionValue($moduleId, $name, $value, $optionSiteId);
			}
			$changed = $valueBefore !== $this->getRealOptionValue($moduleId, $name, $optionSiteId);
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

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

	protected function setOptionValue(string $moduleId, string $name, string $value, string $siteId): void
	{
		Option::set($moduleId, $name, $value, $siteId);
	}
}
