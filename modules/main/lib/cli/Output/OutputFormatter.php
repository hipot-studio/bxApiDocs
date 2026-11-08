<?php

namespace Bitrix\Main\Cli\Output;

use JsonException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class OutputFormatter
{
	/**
	 * @throws JsonException
	 */
	public function writeSuccess(
		OutputInterface $output,
		OutputFormat $format,
		mixed $data,
		array $meta = [],
		array $columns = [],
	): void
	{
		if ($format === OutputFormat::Json)
		{
			$this->writeJson(
				$output,
				$this->createEnvelope(true, $data, $meta, null, null),
			);

			return;
		}

		$table = new Table($output);
		$table->setHeaders(array_values($columns));
		$table->setRows($this->createTableRows($data, array_keys($columns)));
		$table->render();
	}

	/**
	 * @throws JsonException
	 */
	public function writeError(
		OutputInterface $output,
		OutputFormat $format,
		string $code,
		string $message,
	): void
	{
		$errorOutput = $output instanceof ConsoleOutputInterface
			? $output->getErrorOutput()
			: $output
		;

		if ($format === OutputFormat::Json)
		{
			$this->writeJson(
				$errorOutput,
				$this->createEnvelope(false, null, [], $code, $message),
			);

			return;
		}

		$errorOutput->writeln($message);
	}

	private function createEnvelope(
		bool $success,
		mixed $data,
		array $meta,
		?string $errorCode,
		?string $errorMessage,
	): array
	{
		return [
			'success' => $success,
			'data' => $data,
			'meta' => (object)$meta,
			'error' => [
				'code' => $errorCode,
				'message' => $errorMessage,
			],
		];
	}

	private function createTableRows(mixed $data, array $fields): array
	{
		$records = is_array($data) && array_is_list($data) ? $data : [$data];
		$rows = [];

		foreach ($records as $record)
		{
			$record = is_object($record) ? get_object_vars($record) : $record;

			if (!is_array($record))
			{
				$rows[] = [$record];
				continue;
			}

			$rows[] = array_map(
				static fn(string|int $field): mixed => $record[$field] ?? null,
				$fields,
			);
		}

		return $rows;
	}

	/**
	 * @throws JsonException
	 */
	private function writeJson(OutputInterface $output, array $payload): void
	{
		$output->writeln(
			json_encode(
				$payload,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
			),
			OutputInterface::OUTPUT_RAW,
		);
	}
}
