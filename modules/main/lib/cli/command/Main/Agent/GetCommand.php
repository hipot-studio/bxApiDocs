<?php

namespace Bitrix\Main\Cli\Command\Main\Agent;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetCommand extends AbstractFormattedCommand
{
	public function __construct(OutputFormatter $outputFormatter)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:agent:get')
			->setDescription('Get agent information')
			->addArgument(
				'id',
				InputArgument::REQUIRED,
				'Agent ID',
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		$id = (int)$input->getArgument('id');
		if ($id <= 0)
		{
			return $this->renderError(
				$output,
				'INVALID_ARGUMENT',
				'Agent ID must be a positive integer',
				self::INVALID,
			);
		}

		$row = $this->fetchAgentById($id);
		if (!is_array($row))
		{
			return $this->renderError(
				$output,
				'INVALID_ARGUMENT',
				'Agent not found',
				self::INVALID,
			);
		}

		$agent = self::buildAgentRecord($row);

		return $this->renderSuccess(
			$output,
			$agent,
			columns: [
				'id' => 'ID',
				'moduleId' => 'Module',
				'name' => 'Name',
				'active' => 'Active',
				'running' => 'Running',
				'lastExec' => 'Last exec',
				'nextExec' => 'Next exec',
				'dateCheck' => 'Date check',
				'agentInterval' => 'Interval',
				'isPeriodic' => 'Periodic',
				'sort' => 'Sort',
				'userId' => 'User ID',
				'retryCount' => 'Retry count',
			],
		);
	}

	protected function fetchAgentById(int $id): ?array
	{
		$row = \CAgent::GetById($id)->Fetch();

		return is_array($row) ? $row : null;
	}

	private static function buildAgentRecord(array $row): array
	{
		return [
			'id' => (int)$row['ID'],
			'moduleId' => self::nullableString($row['MODULE_ID'] ?? null),
			'name' => (string)$row['NAME'],
			'active' => ($row['ACTIVE'] ?? null) === 'Y',
			'running' => ($row['RUNNING'] ?? null) === 'Y',
			'lastExec' => self::nullableString($row['LAST_EXEC'] ?? null),
			'nextExec' => self::nullableString($row['NEXT_EXEC'] ?? null),
			'dateCheck' => self::nullableString($row['DATE_CHECK'] ?? null),
			'agentInterval' => (int)($row['AGENT_INTERVAL'] ?? 0),
			'isPeriodic' => ($row['IS_PERIOD'] ?? null) === 'Y',
			'sort' => (int)($row['SORT'] ?? 0),
			'userId' => isset($row['USER_ID']) ? (int)$row['USER_ID'] : null,
			'retryCount' => (int)($row['RETRY_COUNT'] ?? 0),
		];
	}

	private static function nullableString(mixed $value): ?string
	{
		return $value === null || $value === '' ? null : (string)$value;
	}
}
