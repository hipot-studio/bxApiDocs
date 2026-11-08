<?php

namespace Bitrix\Main\Cli\Command\Main;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Input\InputSourceReader;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Cli\Security\UnsafeCommandGuard;
use Bitrix\Main\DB\Connection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class SqlCommand extends AbstractFormattedCommand
{
	private const DEFAULT_LIMIT = 1000;
	private const MIN_LIMIT = 1;
	private const MAX_LIMIT = 10000;

	public function __construct(
		private readonly Connection $connection,
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
			->setName('main:sql')
			->setDescription('Execute single SQL block')
			->addOption(
				'file',
				null,
				InputOption::VALUE_REQUIRED,
				'Path to SQL query file. Without this option query is read from standard input',
			)
			->addOption(
				'limit',
				null,
				InputOption::VALUE_REQUIRED,
				'Maximum number of rows in result: from 1 to 10,000',
				self::DEFAULT_LIMIT,
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
				'SQL execution is disabled. Locally define BX_LOCAL_CLI_ALLOW_UNSAFE_COMMANDS constant with value true',
			);
		}

		$limit = $this->getLimit($input->getOption('limit'));
		if ($limit === null)
		{
			return $this->renderError(
				$output,
				'INVALID_LIMIT',
				'Specify integer limit from 1 to 10,000',
				self::INVALID,
			);
		}

		$filePath = $input->getOption('file');
		$filePath = is_string($filePath) ? $filePath : null;

		try
		{
			$sql = $this->inputSourceReader->read(
				$filePath,
				$this->readStandardInput($input),
			);
		}
		catch (InvalidArgumentException|RuntimeException)
		{
			return $this->renderError(
				$output,
				'INVALID_INPUT',
				'Failed to read SQL query. Pass it via --file or standard input',
				self::INVALID,
			);
		}

		try
		{
			$result = $this->executeSql($sql, $limit);
		}
		catch (Throwable)
		{
			return $this->renderError(
				$output,
				'SQL_EXECUTION_FAILED',
				'Failed to execute SQL query',
			);
		}

		return $this->renderSuccess(
			$output,
			$result['data'],
			$result['meta'],
			$this->getColumns($result['data']),
		);
	}

	private function getLimit(mixed $value): ?int
	{
		if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value) === 1))
		{
			return null;
		}

		$limit = (int)$value;

		return $limit >= self::MIN_LIMIT && $limit <= self::MAX_LIMIT ? $limit : null;
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

	private function getColumns(array $data): array
	{
		if (array_is_list($data))
		{
			$firstRow = $data[0] ?? [];
			if (!is_array($firstRow))
			{
				return [];
			}

			$columns = [];
			foreach (array_keys($firstRow) as $column)
			{
				$columns[$column] = $column;
			}

			return $columns;
		}

		return [
			'affectedRows' => 'Affected rows',
		];
	}

	private function executeSql(string $sql, int $limit): array
	{
		$result = $this->connection->query($sql);
		if ($result->getFieldsCount() === 0)
		{
			return [
				'data' => ['affectedRows' => (int)$this->connection->getAffectedRowsCount()],
				'meta' => ['truncated' => false],
			];
		}

		$rows = [];
		while (count($rows) <= $limit && ($row = $result->fetch()) !== false)
		{
			$rows[] = $row;
		}
		$truncated = count($rows) > $limit;
		if ($truncated)
		{
			array_pop($rows);
		}

		return ['data' => $rows, 'meta' => ['limit' => $limit, 'truncated' => $truncated]];
	}
}
