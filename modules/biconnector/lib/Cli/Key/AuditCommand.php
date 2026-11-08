<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Cli\Key;

use Bitrix\BIConnector\Internal\Services\Key\IssuedKeyAuditor;
use Bitrix\Main\Loader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the access keys issued through REST applications and marks the suspicious ones.
 *
 * Usage:
 *   php bitrix/bitrix.php biconnector:key:audit
 *   php bitrix/bitrix.php biconnector:key:audit --format=json
 */
final class AuditCommand extends Command
{
	public function isEnabled(): bool
	{
		return Loader::includeModule('biconnector');
	}

	protected function configure(): void
	{
		$this
			->setName('biconnector:key:audit')
			->setDescription('List BI Connector access keys issued through REST applications')
			->addOption(
				'format',
				null,
				InputOption::VALUE_REQUIRED,
				'Output format: ' . KeyReport::FORMAT_TABLE . ' or ' . KeyReport::FORMAT_JSON,
				KeyReport::FORMAT_TABLE,
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$format = (string)$input->getOption('format');
		if (!KeyReport::isKnownFormat($format))
		{
			$output->writeln('<error>Unknown format: ' . $format . '</error>');

			return self::INVALID;
		}

		$auditor = new IssuedKeyAuditor();
		$rows = [];
		$suspiciousCount = 0;
		foreach ($auditor->collect() as $row)
		{
			$suspicious = $auditor->isSuspicious($row);
			$suspiciousCount += $suspicious ? 1 : 0;
			$rows[] = KeyReport::presentRow($row, $suspicious);
		}

		if ($format === KeyReport::FORMAT_JSON)
		{
			$output->writeln((string)json_encode(
				[
					'keys' => $rows,
					'total' => count($rows),
					'suspicious' => $suspiciousCount,
					'note' => IssuedKeyAuditor::SCOPE_NOTE,
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
			));

			return self::SUCCESS;
		}

		if (!$rows)
		{
			$output->writeln('No access keys issued through REST applications.');
		}
		else
		{
			$table = new Table($output);
			$table->setHeaders(['ID', 'APP_ID', 'CREATED_BY', 'ACTIVE', 'DATE_CREATE', 'LAST_ACTIVITY', 'CONNECTION', 'USED', 'SUSPICIOUS']);
			foreach ($rows as $row)
			{
				$table->addRow([
					$row['id'],
					$row['appId'],
					$row['createdBy'],
					$row['active'],
					$row['dateCreate'] ?? '',
					$row['lastActivityDate'] ?? '',
					$row['connection'],
					$row['wasUsed'] ? 'yes' : 'no',
					$row['suspicious'] ? 'YES' : '',
				]);
			}
			$table->render();

			$output->writeln('Total: ' . count($rows) . ', suspicious: ' . $suspiciousCount . '.');
		}

		$output->writeln(IssuedKeyAuditor::SCOPE_NOTE);

		return self::SUCCESS;
	}
}
