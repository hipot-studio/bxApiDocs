<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\Update\Stepper;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\Replication\Template\Time\Enum\Period;
use Exception;

final class ReplicateParamsConverter extends Stepper
{
	private const LOCK_TIMEOUT = 1;
	private const LIMIT = 25;
	protected static $moduleId = 'tasks';
	private int $lastId = 0;
	private array $templates = [];

	public function execute(array &$option): bool
	{
		$this->setLastId((int)($option['lastId'] ?? 0));

		try
		{
			$this->fillTemplates();
		}
		catch (Exception $exception)
		{
			LogFacade::logThrowable($exception);

			return self::CONTINUE_EXECUTION;
		}

		if (empty($this->templates))
		{
			return self::FINISH_EXECUTION;
		}

		$lastId = $this->convertTemplates();

		$this->updateLastId($option, $lastId);

		return self::CONTINUE_EXECUTION;
	}

	private function fillTemplates(): void
	{
		$this->templates =
			TemplateTable::query()
				->setSelect(['ID', 'REPLICATE_PARAMS'])
				->where('ID', '>', $this->lastId)
				->whereNotNull('REPLICATE_PARAMS')
				->whereNot('REPLICATE_PARAMS', '')
				->setOrder(['ID' => 'ASC'])
				->setLimit(self::LIMIT)
				->fetchAll()
		;
	}

	private function convertTemplates(): int
	{
		$connection = Application::getConnection();

		$lockName = 'replicate_params_converter_lock';
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return $this->lastId;
		}

		try
		{
			foreach ($this->templates as $template)
			{
				$this->convertTemplate($template);
			}

			$templateIds = array_map('intval', array_column($this->templates, 'ID'));

			return max($templateIds);
		}
		catch (Exception $exception)
		{
			LogFacade::logThrowable($exception);
		}
		finally
		{
			$connection->unlock($lockName);
		}

		return $this->lastId;
	}

	private function setLastId(int $id): void
	{
		$this->lastId = $id;
	}

	private function updateLastId(array &$options, int $lastId): void
	{
		$this->lastId = $lastId;

		$options['lastId'] = $this->lastId;
	}

	private function convertTemplate(array $template): void
	{
		$replicateParams = unserialize($template['REPLICATE_PARAMS'], ['allowed_classes' => false]);
		if (!is_array($replicateParams))
		{
			return;
		}

		$replicationType = $replicateParams['PERIOD'] ?? null;
		if ($replicationType !== Period::DAILY)
		{
			return;
		}

		$dailyMonthInterval = (int)($replicateParams['DAILY_MONTH_INTERVAL'] ?? 0);
		if ($dailyMonthInterval <= 0)
		{
			return;
		}

		$everyDay = (int)($replicateParams['EVERY_DAY'] ?? 0);
		$replicateParams['EVERY_DAY'] = $everyDay + $dailyMonthInterval * Options::DAYS_IN_MONTH;
		$replicateParams['DAILY_MONTH_INTERVAL'] = 0;

		try
		{
			TemplateTable::update((int)$template['ID'], [
				'REPLICATE_PARAMS' => serialize($replicateParams),
			]);
		}
		catch (Exception $exception)
		{
			LogFacade::logThrowable($exception);
		}
	}
}
