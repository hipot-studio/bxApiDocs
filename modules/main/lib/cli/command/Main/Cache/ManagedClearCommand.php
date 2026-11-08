<?php

namespace Bitrix\Main\Cli\Command\Main\Cache;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Cli\Validation\LogicalCachePathValidator;
use Bitrix\Main\Data\ManagedCache;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ManagedClearCommand extends AbstractFormattedCommand
{
	public function __construct(
		private readonly ManagedCache $managedCache,
		OutputFormatter $outputFormatter,
	)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
			$this
				->setName('main:managed-cache:clear')
				->setDescription('Clear specified folder, entry, or entire managed cache')
			->addArgument(
				'folder',
				InputArgument::OPTIONAL,
				'Logical managed cache folder',
			)
			->addArgument(
				'path',
				InputArgument::OPTIONAL,
				'Entry identifier in logical managed cache folder',
			)
			->addOption(
				'all',
				null,
				InputOption::VALUE_NONE,
				'Clear entire managed cache when used with --force',
			)
			->addOption(
				'force',
				null,
				InputOption::VALUE_NONE,
				'Confirm full managed cache clear when used with --all',
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$folder = $this->getOptionalStringArgument($input, 'folder');
			$path = $this->getOptionalStringArgument($input, 'path');
			$all = $input->getOption('all') === true;
			$force = $input->getOption('force') === true;
			if ($all || $force)
			{
				if (!$all || !$force)
				{
					throw new InvalidArgumentException(
						'Full managed cache clear requires --all together with --force',
					);
				}
				if ($folder !== null || $path !== null)
				{
					throw new InvalidArgumentException(
						'Cannot combine targeted and full managed cache clear',
					);
				}
				$this->managedCache->cleanAll();
				$scope = 'all';
			}
			else
			{
				if ($folder === null)
				{
					throw new InvalidArgumentException(
						'Specify managed cache folder',
					);
				}
				$folder = LogicalCachePathValidator::validate($folder);
				if ($path === null)
				{
					$this->managedCache->cleanDir($folder);
					$scope = 'folder';
				}
				else
				{
					$path = LogicalCachePathValidator::validate($path);
					$this->managedCache->clean($path, $folder);
					$scope = 'path';
				}
			}
			$result = [
				'engine' => 'managed_cache',
				'scope' => $scope,
				'folder' => $folder,
				'path' => $path,
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
			],
		);
	}

	private function getOptionalStringArgument(InputInterface $input, string $name): ?string
	{
		$value = $input->getArgument($name);

		return is_string($value) ? $value : null;
	}
}
