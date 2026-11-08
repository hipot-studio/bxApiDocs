<?php

namespace Bitrix\Main\Cli\Command\Main\Settings\Smtp;

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
			->setName('main:settings:smtp-set')
			->setDescription('Patch smtp section in .settings.php')
			->addOption(
				'enabled',
				null,
				InputOption::VALUE_NEGATABLE,
				'SMTP enabled flag (use --no-enabled to disable)',
			)
			->addOption('host', null, InputOption::VALUE_REQUIRED, 'SMTP host')
			->addOption('port', null, InputOption::VALUE_REQUIRED, 'SMTP port')
			->addOption('login', null, InputOption::VALUE_REQUIRED, 'SMTP login')
			->addOption('password', null, InputOption::VALUE_REQUIRED, 'SMTP password')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'SMTP from address')
			->addOption(
				'readonly',
				null,
				InputOption::VALUE_NEGATABLE,
				'Write section as readonly (use --no-readonly to keep it writable)',
				true,
			)
			->setHelp(<<<'HELP'
Patches selected fields of the smtp section in .settings.php. Unspecified fields are left unchanged.

Examples:

  php bitrix.php main:settings:smtp-set --no-enabled
  php bitrix.php main:settings:smtp-set --enabled --host=smtp.example.com --port=465 --login=user --password=secret --from=user@example.com
  php bitrix.php main:settings:smtp-set --host=smtp.example.com --no-readonly
HELP
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$readonly = (bool)$input->getOption('readonly');
			$value = $this->settingsConfigurator->applySmtp($this->collectOptions($input), $readonly);
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

		return $this->renderSuccess(
			$output,
			[
				'section' => SettingsConfigurator::SECTION_SMTP,
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

		$enabled = $input->getOption('enabled');
		if (is_bool($enabled))
		{
			$options['enabled'] = $enabled;
		}

		foreach (['host', 'port', 'login', 'password', 'from'] as $name)
		{
			$value = $input->getOption($name);
			if (is_string($value))
			{
				$options[$name] = $value;
			}
		}

		return $options;
	}
}
