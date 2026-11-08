<?php

namespace Bitrix\Main\Cli\Command\Main\Settings\ExceptionHandling;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Command\Main\Settings\SettingsConfigurator;
use Bitrix\Main\Cli\Output\OutputFormatter;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SetCommand extends AbstractFormattedCommand
{
	public function __construct(
		private readonly SettingsConfigurator $settingsConfigurator,
		OutputFormatter $outputFormatter,
	)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:settings:exception-handling-set')
			->setDescription('Patch exception_handling section in .settings.php')
			->addOption(
				'debug',
				null,
				InputOption::VALUE_NEGATABLE,
				'Exception debug mode (use --no-debug to disable)',
			)
			->addOption('log-file', null, InputOption::VALUE_REQUIRED, 'Exception log file path')
			->addOption(
				'readonly',
				null,
				InputOption::VALUE_NEGATABLE,
				'Write section as readonly (use --no-readonly to keep it writable)',
				true,
			)
			->setHelp(<<<'HELP'
Patches debug and/or log file of the exception_handling section. Other keys in the section are left unchanged.

Examples:

  php bitrix.php main:settings:exception-handling-set --debug
  php bitrix.php main:settings:exception-handling-set --log-file=bitrix/modules/error.log
  php bitrix.php main:settings:exception-handling-set --no-debug --no-readonly
HELP
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$readonly = (bool)$input->getOption('readonly');
			$value = $this->settingsConfigurator->applyExceptionHandling($this->collectOptions($input), $readonly);
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

		return $this->renderSuccess(
			$output,
			[
				'section' => SettingsConfigurator::SECTION_EXCEPTION_HANDLING,
				'value' => $value,
			],
			columns: [
				'section' => 'Section',
				'value' => 'Value',
			],
		);
	}

	private function collectOptions(InputInterface $input): array
	{
		$options = [];
		$debug = $input->getOption('debug');
		if (is_bool($debug))
		{
			$options['debug'] = $debug;
		}
		$logFile = $this->getOptionalOption($input, 'log-file');
		if ($logFile !== null)
		{
			$options['log_file'] = $logFile;
		}

		return $options;
	}

	private function getOptionalOption(InputInterface $input, string $name): ?string
	{
		$value = $input->getOption($name);

		return is_string($value) ? $value : null;
	}
}
