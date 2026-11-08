<?php

namespace Bitrix\Main\Cli\Command\Main;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Input\InputSourceReader;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Cli\Security\UnsafeCommandGuard;
use Bitrix\Main\Web\Json;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class PhpEvalCommand extends AbstractFormattedCommand
{
	public function __construct(
		private readonly InputSourceReader $inputSourceReader,
		private readonly UnsafeCommandGuard $unsafeCommandGuard,
		OutputFormatter $outputFormatter,
	)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:php-eval')
			->setDescription('Execute PHP code in loaded Bitrix24 environment')
			->setHelp(
				'The command executes PHP code body without opening tag in current process. '
				. 'Pass code via --file or standard input. There is no sandbox: code gets full process context and privileges. '
				. 'Calling exit, a fatal error, or running out of memory may terminate the process without a normal JSON response.',
			)
			->addOption(
				'file',
				null,
				InputOption::VALUE_REQUIRED,
				'Path to PHP code body file without opening tag. Without this option code is read from standard input',
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$this->unsafeCommandGuard->assertAllowed();
		}
		catch (RuntimeException)
		{
			return $this->renderError(
				$output,
				'UNSAFE_COMMAND_DISABLED',
				'PHP code execution is disabled. Locally define BX_LOCAL_CLI_ALLOW_UNSAFE_COMMANDS constant with value true',
			);
		}

		$filePath = $input->getOption('file');
		$filePath = is_string($filePath) ? $filePath : null;

		try
		{
			$code = $this->inputSourceReader->read(
				$filePath,
				$this->readStandardInput($input),
			);
		}
		catch (InvalidArgumentException|RuntimeException)
		{
			return $this->renderError(
				$output,
				'INVALID_INPUT',
				'Failed to read PHP code. Pass it via --file or standard input',
				self::INVALID,
			);
		}

		try
		{
			$result = $this->evaluate($code);
		}
		catch (Throwable)
		{
			return $this->renderError(
				$output,
				'PHP_EVALUATION_FAILED',
				'Failed to execute PHP code',
			);
		}

		return $this->renderSuccess(
			$output,
			$result,
			[],
			[
				'output' => 'Output',
				'returnValue' => 'Return value',
				'returnType' => 'Return type',
			],
		);
	}

	private function readStandardInput(InputInterface $input): string
	{
		if (!$input instanceof StreamableInputInterface)
		{
			return '';
		}

		$stream = $input->getStream();
		if (!is_resource($stream))
		{
			return '';
		}
		if (function_exists('stream_isatty') && stream_isatty($stream))
		{
			return '';
		}

		$contents = stream_get_contents($stream);

		return $contents === false ? '' : $contents;
	}

	private function evaluate(string $code): array
	{
		$initialBufferLevel = ob_get_level();
		ob_start();
		try
		{
			$returnValue = eval($code);
			$output = '';
			while (ob_get_level() > $initialBufferLevel)
			{
				$output = (string)ob_get_clean() . $output;
			}
		}
		catch (Throwable $exception)
		{
			while (ob_get_level() > $initialBufferLevel)
			{
				ob_end_clean();
			}
			throw $exception;
		}

		return Json::decode(Json::encode(
			[
				'output' => $output,
				'returnValue' => $returnValue,
				'returnType' => get_debug_type($returnValue),
			],
			Json::DEFAULT_OPTIONS | JSON_PRESERVE_ZERO_FRACTION,
		));
	}
}
