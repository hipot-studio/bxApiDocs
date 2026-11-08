<?php

namespace Bitrix\Main\Cli\Command\Main\Site;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\SiteTable;
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
				->setName('main:site:list')
				->setDescription('List sites configured in current installation')
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		$sites = [];
		$rows = $this->fetchSites();
		foreach ($rows as $site)
		{
			$sites[] = [
				'id' => (string)$site['LID'],
				'name' => (string)$site['NAME'],
				'siteName' => self::nullableString($site['SITE_NAME']),
				'active' => $site['ACTIVE'] === 'Y',
				'default' => $site['DEF'] === 'Y',
				'sort' => (int)$site['SORT'],
				'directory' => (string)$site['DIR'],
				'languageId' => (string)$site['LANGUAGE_ID'],
				'serverName' => self::nullableString($site['SERVER_NAME']),
				'cultureId' => isset($site['CULTURE_ID']) ? (int)$site['CULTURE_ID'] : null,
			];
		}

		return $this->renderSuccess(
			$output,
			$sites,
			columns: [
					'id' => 'ID',
					'name' => 'Name',
					'siteName' => 'Site name',
					'active' => 'Active',
					'default' => 'Default',
					'sort' => 'Sort',
					'directory' => 'Directory',
					'languageId' => 'Language ID',
					'serverName' => 'Server name',
					'cultureId' => 'Culture ID',
			],
		);
	}

	protected function fetchSites(): array
	{
		return SiteTable::query()
			->setSelect(['LID', 'NAME', 'SITE_NAME', 'ACTIVE', 'DEF', 'SORT', 'DIR', 'LANGUAGE_ID', 'SERVER_NAME', 'CULTURE_ID'])
			->setOrder(['SORT' => 'ASC', 'LID' => 'ASC'])
			->fetchAll()
		;
	}

	private static function nullableString(mixed $value): ?string
	{
		return $value === null || $value === '' ? null : (string)$value;
	}
}
