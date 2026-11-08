<?php

namespace Bitrix\Main\Cli\Command\Main\Settings\Crypto;

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
			->setName('main:settings:crypto-set')
			->setDescription('Replace crypto section in .settings.php')
			->addOption('key', null, InputOption::VALUE_REQUIRED, 'Crypto key value')
			->addOption(
				'readonly',
				null,
				InputOption::VALUE_NEGATABLE,
				'Write section as readonly (use --no-readonly to keep it writable)',
				true,
			)
			->setHelp(<<<'HELP'
Replaces the entire crypto section in .settings.php with a crypto_key.

Examples:

  php bitrix.php main:settings:crypto-set
  php bitrix.php main:settings:crypto-set --key=your32charactersecretkeyvalue12
  php bitrix.php main:settings:crypto-set --no-readonly
HELP
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$readonly = (bool)$input->getOption('readonly');
			$options = [];
			$key = $this->getOptionalOption($input, 'key');
			if ($key !== null)
			{
				$options['key'] = $key;
			}
			$value = $this->settingsConfigurator->applyCrypto($options, $readonly);
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

		return $this->renderSuccess(
			$output,
			[
				'section' => SettingsConfigurator::SECTION_CRYPTO,
				'value' => $value,
			],
			columns: [
				'section' => 'Section',
				'value' => 'Value',
			],
		);
	}

	private function getOptionalOption(InputInterface $input, string $name): ?string
	{
		$value = $input->getOption($name);

		return is_string($value) ? $value : null;
	}
}
