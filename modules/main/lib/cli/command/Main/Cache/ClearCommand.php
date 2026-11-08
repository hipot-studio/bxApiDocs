<?php

namespace Bitrix\Main\Cli\Command\Main\Cache;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Cli\Validation\LogicalCachePathValidator;
use Bitrix\Main\Data\Cache;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ClearCommand extends AbstractFormattedCommand
{
	private const ALLOWED_BASE_DIRS = ['cache', 'stack_cache'];

	public function __construct(
		private readonly Cache $cache,
		OutputFormatter $outputFormatter,
	)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
			$this
				->setName('main:cache:clear')
				->setDescription('Clear logical cache folder or single entry')
			->addArgument(
				'folder',
				InputArgument::REQUIRED,
				'Logical cache folder',
			)
			->addArgument(
				'path',
				InputArgument::OPTIONAL,
				'Entry identifier in logical cache folder',
			)
			->addOption(
				'base-dir',
				null,
				InputOption::VALUE_REQUIRED,
				'Logical cache base directory',
				'cache',
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$folder = LogicalCachePathValidator::validate((string)$input->getArgument('folder'));
			$baseDir = LogicalCachePathValidator::validate((string)$input->getOption('base-dir'));
			if (!in_array($baseDir, self::ALLOWED_BASE_DIRS, true))
			{
				throw new InvalidArgumentException(
					'Cache base directory is not supported',
				);
			}
			$path = $this->getOptionalStringArgument($input, 'path');
			if ($path === null)
			{
				$this->cache->cleanDir($folder, $baseDir);
				$scope = 'folder';
			}
			else
			{
				$path = LogicalCachePathValidator::validate($path);
				$this->cache->clean($path, $folder, $baseDir);
				$scope = 'path';
			}

			$result = [
				'engine' => 'cache',
				'scope' => $scope,
				'folder' => $folder,
				'path' => $path,
				'baseDir' => $baseDir,
			];
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

		return $this->renderSuccess(
			$output,
			$result,
			columns: [
					'engine' => 'Engine',
					'scope' => 'Scope',
					'folder' => 'Folder',
					'path' => 'Path',
					'baseDir' => 'Base dir',
			],
		);
	}

	private function getOptionalStringArgument(InputInterface $input, string $name): ?string
	{
		$value = $input->getArgument($name);

		return is_string($value) ? $value : null;
	}
}
