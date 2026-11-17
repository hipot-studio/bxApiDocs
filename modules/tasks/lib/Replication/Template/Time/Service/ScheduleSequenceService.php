<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Time\Service;

use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Replication\Repository\TemplateRepository;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\Replication\Template\Repetition\Parameter\ReplicateParameter;
use Bitrix\Tasks\Replication\Template\Time\Enum\RepeatType;
use Bitrix\Tasks\Replication\Template\Time\Factory\ExecutionTimeFactory;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\User;

/**
 * Проекция последовательности будущих постановок правила на период (ALG-S1, P2.T1).
 *
 * Крутит детерминированные фабрики Части I в цикле: каждый шаг считает следующую дату
 * от предыдущей опорной точки (`base = prev + 1s`), пока не выйдем за periodTo или не
 * достигнем лимита. Опорная точка для schedule — дата исполнения, для deadline — сам
 * дедлайн (серия упорядочена по дедлайнам), а в результат идёт дата постановки.
 * Логику фабрик не дублирует — только цикл-обёртка поверх
 * {@see ExecutionTimeFactory::getNextExecutionTime()} (schedule) и
 * {@see DeadlineCreationTimeService::calculate()} (deadline).
 *
 * Для `after_completion` возвращает пустую последовательность: будущее событийно
 * непрогнозируемо (AC-S-068). Календарный хвост (31-е / 29 фев) и clamp — уже в
 * фабриках (ERR-S-009), заново не реализуется.
 *
 * Времена — серверные unix-timestamps (как в фабриках); приведение к поясу
 * просматривающего — на этапе бакетизации/рендера (P2.T2).
 */
class ScheduleSequenceService
{
	private const DEFAULT_LIMIT = 366;

	/** @var null|\Closure(string, int): void */
	private readonly ?\Closure $transitionObserver;

	/**
	 * @param null|callable('step'|'jump'|'century', int): void $transitionObserver
	 */
	public function __construct(
		private readonly DeadlineCreationTimeService $deadlineCreationTimeService = new DeadlineCreationTimeService(),
		?callable $transitionObserver = null,
	)
	{
		$this->transitionObserver = $transitionObserver === null
			? null
			: \Closure::fromCallable($transitionObserver)
		;
	}

	/**
	 * @return int[] серверные timestamps постановок в [periodFrom; periodTo]
	 */
	public function getSequenceByTemplateId(
		int $templateId,
		int $periodFrom,
		int $periodTo,
		int $limit = self::DEFAULT_LIMIT,
	): array
	{
		$template = TemplateRepository::getInstance($templateId)->getEntity();
		if ($template === null)
		{
			return [];
		}

		return $this->getSequence($template, $periodFrom, $periodTo, $limit);
	}

	/**
	 * @return int[] серверные timestamps постановок в [periodFrom; periodTo]
	 */
	public function getSequence(
		TemplateObject $template,
		int $periodFrom,
		int $periodTo,
		int $limit = self::DEFAULT_LIMIT,
	): array
	{
		$type = RegularityType::fromValue($template->getReplicateType());
		if ($type === RegularityType::AfterCompletion)
		{
			return []; // будущее не проецируется (AC-S-068)
		}

		// non-null REPLICATE_TYPE ⇒ unified working-days semantics (weekend shift extended
		// to monthly/yearly by-date); null ⇒ legacy schedule, master behaviour
		$isTyped = $template->getReplicateType() !== null;

		if ($periodTo < $periodFrom || $limit < 1)
		{
			return [];
		}

		$repository = TemplateRepository::getInstance((int)$template->getId())->inject($template);
		$parameter = new ReplicateParameter($repository);
		$options = Options::validate($parameter->getData());
		$limit = $this->applyRepetitionLimit($template, $options, $limit, 0);
		if ($limit < 1)
		{
			return [];
		}

		$context = $this->assembleContext($template, $options);
		$preferredTS = $context['preferredTS'];
		$startTS = $context['startTS'];

		$periodTo = $this->applyEndDate($options, $context['endTS'], $periodTo);
		if ($periodTo < $periodFrom)
		{
			return [];
		}

		$base = $this->resolveInitialBase($context, $periodFrom);
		$searchResult = $this->getOccurrenceAtOrAfter(
			$type,
			$base,
			$periodFrom,
			$preferredTS,
			$startTS,
			$parameter,
			$isTyped,
		);
		$limit = $this->applyRepetitionLimit($template, $options, $limit, $searchResult->skipped);
		if ($limit < 1)
		{
			return [];
		}
		$occurrence = $searchResult->occurrence;
		$out = [];

		while (count($out) < $limit)
		{
			$next = $occurrence->creationTime;
			$step = $occurrence->deadline;

			if ($step <= 0 || $step <= $base)
			{
				// защита от неподвижной точки (некорректный payload) — не зациклиться молча
				break;
			}

			if ($next > $periodTo)
			{
				break;
			}

			if ($next >= $periodFrom)
			{
				$out[] = $next;
			}
			if (count($out) >= $limit)
			{
				break;
			}

			$base = $step + 1; // шаг серии от предыдущей опорной точки (дедлайн / дата исполнения)
			$occurrence = $this->getNextOccurrence(
				$type,
				$base,
				$preferredTS,
				$startTS,
				$parameter,
				$isTyped,
			);
		}

		return $out;
	}

	protected function getOccurrenceAtOrAfter(
		RegularityType $type,
		int $base,
		int $boundary,
		int $preferredTS,
		int $startTS,
		ReplicateParameter $parameter,
		bool $isTyped,
	): OccurrenceSearchResult
	{
		if ($type === RegularityType::Deadline)
		{
			return $this->deadlineCreationTimeService->calculateAtOrAfter(
				$base,
				$boundary,
				$preferredTS,
				$startTS,
				$parameter,
				$this->transitionObserver,
			);
		}

		return ExecutionTimeFactory::getNextExecutionTimeAtOrAfter(
			$base,
			$boundary,
			$preferredTS,
			$parameter,
			$isTyped,
			$this->transitionObserver,
		);
	}

	protected function getNextOccurrence(
		RegularityType $type,
		int $base,
		int $preferredTS,
		int $startTS,
		ReplicateParameter $parameter,
		bool $isTyped,
	): DeadlineCreationTime
	{
		if ($type === RegularityType::Deadline)
		{
			$occurrence = $this->deadlineCreationTimeService->calculate(
				$base,
				$preferredTS,
				$startTS,
				$parameter,
			);
			$this->notifyTransition('step', 1);

			return $occurrence;
		}

		$next = ExecutionTimeFactory::getNextExecutionTime($base, $preferredTS, $parameter, $isTyped);
		$this->notifyTransition('step', 1);

		return new DeadlineCreationTime($next, $next);
	}

	/**
	 * Checks whether a finite rule still has a projected occurrence after the boundary.
	 */
	public function canContinueAfter(TemplateObject $template, int $timestamp): bool
	{
		if (RegularityType::fromValue($template->getReplicateType()) === RegularityType::AfterCompletion)
		{
			return false;
		}

		$repository = TemplateRepository::getInstance((int)$template->getId())->inject($template);
		$options = Options::validate((new ReplicateParameter($repository))->getData());

		if (($options['REPEAT_TILL'] ?? null) === RepeatType::TIMES)
		{
			$remaining = $this->getRemainingRepetitions($template, $options);
			if ($remaining < 1)
			{
				return false;
			}

			$parameter = new ReplicateParameter($repository);
			$context = $this->assembleContext($template, $options);
			$base = $this->resolveInitialBase($context, $timestamp);
			$searchResult = $this->getOccurrenceAtOrAfter(
				RegularityType::fromValue($template->getReplicateType()),
				$base,
				$timestamp < PHP_INT_MAX ? $timestamp + 1 : $timestamp,
				$context['preferredTS'],
				$context['startTS'],
				$parameter,
				$template->getReplicateType() !== null,
			);

			return $searchResult->occurrence->deadline > 0 && $searchResult->skipped < $remaining;
		}

		if (($options['REPEAT_TILL'] ?? null) === RepeatType::DATE)
		{
			$endTS = $this->assembleContext($template, $options)['endTS'];

			return $endTS > $timestamp;
		}

		return true;
	}

	/**
	 * Минимальный контекст времени (preferred HH:MM как секунды суток, старт повтора),
	 * зеркалит {@see \Bitrix\Tasks\Replication\Template\Repetition\Time\Service\ExecutionService::assembleExecutionContext()}
	 * — так проекция совпадает с фактической постановкой.
	 *
	 * @return array{preferredTS:int, startTS:int, endTS:int, nextExecutionTS:int}
	 */
	private function assembleContext(TemplateObject $template, array $options): array
	{
		// deprecated: TIMEZONE_OFFSET в новых шаблонах всегда 0 → пояс постановщика.
		$timeZoneOffsetTS = $options['TIMEZONE_OFFSET'] ?? null;
		$creatorTimeZoneOffsetTS = $timeZoneOffsetTS ?? User::getTimeZoneOffset($template->getCreatedBy());

		$replicationTimeTS = strtotime((string)($options['TIME'] ?? ''));
		$preferredTS = UI::parseTimeAmount(
			date('H:i', $replicationTimeTS - $creatorTimeZoneOffsetTS),
			'HH:MI',
		);

		$startTS = 0;
		$replicationStartTime = $options['START_DATE'] ?? null;
		if ($replicationStartTime)
		{
			$startTS = (int)MakeTimeStamp($replicationStartTime) - $creatorTimeZoneOffsetTS;
		}

		$endTS = 0;
		$replicationEndTime = $options['END_DATE'] ?? null;
		if ($replicationEndTime)
		{
			$endTS = (int)MakeTimeStamp($replicationEndTime) - $creatorTimeZoneOffsetTS;
		}

		$nextExecutionTS = 0;
		$nextExecutionTime = $options['NEXT_EXECUTION_TIME'] ?? null;
		if ($nextExecutionTime)
		{
			$nextExecutionTS = (int)MakeTimeStamp($nextExecutionTime);
		}

		return [
			'preferredTS' => (int)$preferredTS,
			'startTS' => $startTS,
			'endTS' => $endTS,
			'nextExecutionTS' => $nextExecutionTS,
		];
	}

	private function applyRepetitionLimit(
		TemplateObject $template,
		array $options,
		int $limit,
		int $skipped,
	): int
	{
		if (($options['REPEAT_TILL'] ?? null) !== RepeatType::TIMES)
		{
			return $limit;
		}

		$remaining = $this->getRemainingRepetitions($template, $options) - $skipped;

		return min($limit, max(0, $remaining));
	}

	private function getRemainingRepetitions(TemplateObject $template, array $options): int
	{
		return max(
			0,
			(int)($options['TIMES'] ?? 0) - (int)$template->getTparamReplicationCount(),
		);
	}

	/**
	 * @param array{preferredTS:int, startTS:int, endTS:int, nextExecutionTS:int} $context
	 */
	private function resolveInitialBase(array $context, int $fallback): int
	{
		return match (true)
		{
			$context['nextExecutionTS'] > 0 => max(0, $context['nextExecutionTS'] - 1),
			$context['startTS'] > 0 => $context['startTS'],
			default => $fallback,
		};
	}

	private function applyEndDate(array $options, int $endTS, int $periodTo): int
	{
		if (($options['REPEAT_TILL'] ?? null) !== RepeatType::DATE || $endTS <= 0)
		{
			return $periodTo;
		}

		return min($periodTo, $endTS);
	}

	private function notifyTransition(string $type, int $occurrences): void
	{
		if ($this->transitionObserver !== null)
		{
			($this->transitionObserver)($type, $occurrences);
		}
	}
}
