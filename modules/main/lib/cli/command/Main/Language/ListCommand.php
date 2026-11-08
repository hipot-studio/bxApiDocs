<?php

namespace Bitrix\Main\Cli\Command\Main\Language;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\Localization\LanguageTable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends AbstractFormattedCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
			$this
				->setName('main:language:list')
				->setDescription('List languages configured in current installation')
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		$languages = [];
		$rows = $this->fetchLanguages();
		foreach ($rows as $language)
		{
			$languages[] = [
				'id' => (string)$language['LID'],
				'name' => (string)$language['NAME'],
				'code' => self::nullableString($language['CODE']),
				'active' => $language['ACTIVE'] === 'Y',
				'default' => $language['DEF'] === 'Y',
				'sort' => (int)$language['SORT'],
				'cultureId' => isset($language['CULTURE_ID']) ? (int)$language['CULTURE_ID'] : null,
			];
		}

		return $this->renderSuccess(
			$output,
			$languages,
			columns: [
					'id' => 'ID',
					'name' => 'Name',
					'code' => 'Code',
					'active' => 'Active',
					'default' => 'Default',
					'sort' => 'Sort',
					'cultureId' => 'Culture ID',
			],
		);
	}

	protected function fetchLanguages(): array
	{
		return LanguageTable::query()
			->setSelect(['LID', 'NAME', 'CODE', 'ACTIVE', 'DEF', 'SORT', 'CULTURE_ID'])
			->setOrder(['SORT' => 'ASC', 'LID' => 'ASC'])
			->fetchAll()
		;
	}

	private static function nullableString(mixed $value): ?string
	{
		return $value === null || $value === '' ? null : (string)$value;
	}
}
