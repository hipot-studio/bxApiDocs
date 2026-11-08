<?php

namespace Bitrix\Main\Cli\Command;

use Bitrix\Main\Cli\Output\OutputFormat;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class AbstractFormattedCommand extends Command
{
	private OutputFormat $outputFormat = OutputFormat::Table;

	public function __construct(private readonly OutputFormatter $outputFormatter)
	{
		parent::__construct();

		$this->addOption(
			'format',
			null,
			InputOption::VALUE_REQUIRED,
			'Output format: table or json',
			OutputFormat::Table->value,
		);
	}

	final protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$formatValue = (string)$input->getOption('format');
		$this->outputFormat = OutputFormat::tryFrom($formatValue) ?? OutputFormat::Table;

		if (OutputFormat::tryFrom($formatValue) === null)
		{
			$message = sprintf('Output format %s is not supported', $formatValue);

			return $this->renderError($output, 'INVALID_FORMAT', $message, self::INVALID);
		}

		try
		{
			return $this->executeFormatted($input, $output);
		}
		catch (Throwable)
		{
			return $this->renderError($output, 'UNEXPECTED_ERROR', 'Failed to execute command');
		}
	}

	abstract protected function executeFormatted(InputInterface $input, OutputInterface $output): int;

	protected function renderSuccess(
		OutputInterface $output,
		mixed $data,
		array $meta = [],
		array $columns = [],
	): int
	{
		$this->outputFormatter->writeSuccess(
			$output,
			$this->outputFormat,
			$data,
			$meta,
			$columns,
		);

		return self::SUCCESS;
	}

	protected function renderError(
		OutputInterface $output,
		string $code,
		string $message,
		int $exitCode = self::FAILURE,
	): int
	{
		$this->outputFormatter->writeError(
			$output,
			$this->outputFormat,
			$code,
			$message,
		);

		return $exitCode;
	}
}
