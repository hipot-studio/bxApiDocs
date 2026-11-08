<?php

namespace Bitrix\Main\Cli\Command\Main\Cache;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Data\TaggedCache;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TaggedClearCommand extends AbstractFormattedCommand
{
	public function __construct(
		private readonly TaggedCache $taggedCache,
		OutputFormatter $outputFormatter,
	)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
			$this
				->setName('main:tagged-cache:clear')
				->setDescription('Clear cache by tag')
			->addArgument(
				'tag',
				InputArgument::REQUIRED,
				'Cache tag',
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$tag = (string)$input->getArgument('tag');
			if ($tag === '')
			{
				throw new InvalidArgumentException(
					'Cache tag must not be empty',
				);
			}
			$this->taggedCache->clearByTag($tag);
			$result = ['engine' => 'tagged_cache', 'scope' => 'tag', 'tag' => $tag];
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
					'tag' => 'Tag',
			],
		);
	}
}
