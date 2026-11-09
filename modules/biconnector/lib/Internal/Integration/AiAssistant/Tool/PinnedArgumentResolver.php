<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolInterface;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;
use Bitrix\BIConnector\Integration\Superset\Integrator\ActAsFailure;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Internal\Digest\PinnedContextPacker;
use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;
use Bitrix\BIConnector\Internal\Digest\Service\DigestToolFailureJournal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Error;
use Bitrix\Main\Web\Json;

/**
 * Lets the values pinned by the digest run win over the ones the model sent.
 *
 * The pinned schema and the tool argument schemas are different namespaces that
 * only partly share their names: the report period has no argument of its own
 * and travels as an `appliedFilters` entry, so it is substituted there and not
 * under a `period` key no tool reads.
 *
 * A disagreement is recorded and let through: the run is never rejected over
 * it, because the model losing an argument is the normal outcome of pinning.
 * An unreadable envelope is the opposite case and refuses the call: treating it
 * as "nothing was pinned" would summarize whatever report the model picked.
 *
 * A pinned call belongs to a digest run, which waits on Superset less than the AI
 * service waits on the tool: a cold report would otherwise use up the whole wait of
 * the agent, and the recipient would get nothing and no reason.
 */
final class PinnedArgumentResolver
{
	/**
	 * Answers that speak about the request or the setup rather than about the report being reachable.
	 */
	private const REQUEST_REFUSAL_STATUSES = [
		IntegratorResponse::STATUS_BAD_REQUEST,
		IntegratorResponse::STATUS_NO_ACCESS,
		IntegratorResponse::STATUS_NOT_FOUND,
		IntegratorResponse::STATUS_LIMIT_EXCEEDED,
		IntegratorResponse::STATUS_REGISTER_REQUIRED,
	];

	private const OVERRIDE_MARKER = 'pinned_argument_override';

	private const ARGUMENT_DASHBOARD_ID = 'dashboardId';
	private const ARGUMENT_URL_PARAMS = 'urlParams';
	private const ARGUMENT_APPLIED_FILTERS = 'appliedFilters';
	private const ARGUMENT_FILTER_NAME = 'name';
	private const ARGUMENT_FILTER_VALUE = 'value';

	private const PERIOD_FILTER_NAME = 'period';

	private string $attempt = '';
	private string $call = '';

	/**
	 * @param array<string, mixed> $args Arguments as the model sent them.
	 * @return array<string, mixed> Arguments the tool is going to see.
	 * @throws McpException When the subject carries a pinned context that cannot be read.
	 */
	public function apply(ToolInterface $tool, ?RequestSubjectDto $subject, array $args): array
	{
		$pinned = $this->readPinnedContext($subject);
		if ($pinned === null)
		{
			return $args;
		}

		$toolName = $tool->getName();
		$runId = (string)$pinned[PinnedContextPacker::KEY_RUN_ID];
		$declaredArguments = $this->getDeclaredArguments($tool);

		if (in_array(self::ARGUMENT_DASHBOARD_ID, $declaredArguments, true))
		{
			$args = $this->replace(
				$args,
				self::ARGUMENT_DASHBOARD_ID,
				$pinned[PinnedContextPacker::KEY_DASHBOARD_ID],
				self::ARGUMENT_DASHBOARD_ID,
				$toolName,
				$runId,
			);
		}

		if (in_array(self::ARGUMENT_URL_PARAMS, $declaredArguments, true))
		{
			$args = $this->replace(
				$args,
				self::ARGUMENT_URL_PARAMS,
				$pinned[PinnedContextPacker::KEY_URL_PARAMS],
				self::ARGUMENT_URL_PARAMS,
				$toolName,
				$runId,
			);
		}

		$period = $pinned[PinnedContextPacker::KEY_PERIOD] ?? null;
		if ($period !== null && in_array(self::ARGUMENT_APPLIED_FILTERS, $declaredArguments, true))
		{
			$args[self::ARGUMENT_APPLIED_FILTERS] = $this->applyPeriod(
				$args[self::ARGUMENT_APPLIED_FILTERS] ?? [],
				$period,
				$toolName,
				$runId,
			);
		}

		return $args;
	}

	/**
	 * @return string|null The digest run the call is made for; null outside a run.
	 * @throws McpException When the subject carries a pinned context that cannot be read.
	 */
	public function findRunId(?RequestSubjectDto $subject): ?string
	{
		$pinned = $this->readPinnedContext($subject);

		return $pinned === null ? null : (string)$pinned[PinnedContextPacker::KEY_RUN_ID];
	}

	/**
	 * Same as findRunId(), and remembers the attempt of the recipient the call starts in: a failure is
	 * recorded for that attempt, so a call that outlives it cannot mark the next one.
	 *
	 * @param array<string, mixed> $readArguments The arguments that decide which data the call reads, such as
	 *     the charts or the filter column: a call that read other data does not take back the failure of this one.
	 * @return string|null The digest run the call is made for; null outside a run.
	 * @throws McpException When the subject carries a pinned context that cannot be read.
	 */
	public function beginRunCall(
		?RequestSubjectDto $subject,
		int $userId,
		string $toolName,
		array $readArguments = [],
	): ?string
	{
		$runId = $this->findRunId($subject);
		if ($runId !== null)
		{
			$this->attempt = (new DigestToolFailureJournal())->getAttempt($runId, $userId);
			$this->call = self::makeCallKey($toolName, $readArguments);
		}

		return $runId;
	}

	/**
	 * The report answered the call, so an earlier failure of the same read in this attempt no longer stands.
	 */
	public function completeRunCall(?string $runId, int $userId): void
	{
		if ($runId !== null)
		{
			(new DigestToolFailureJournal())->clear($runId, $userId, $this->attempt, $this->call);
		}
	}

	/**
	 * Inside a digest run a report that did not answer is recorded for the recipient, so the step after the
	 * agent can tell it from a failure, and the model is told to stop: another call would wait just as long.
	 *
	 * A read refused because it could not run as the person - an outdated box, a substitution Superset did not
	 * confirm, a table closed by the rights of the person - is not the report being slow: waiting does not change
	 * it, so it is left to the reason the caller gives outside a run.
	 *
	 * @param array $context Log context of the failed call.
	 * @return McpException|null Null when the call is not made for a run, Superset refused the request itself
	 *     or the request could not run as the person.
	 */
	public function findRunFailure(
		?string $runId,
		int $userId,
		IntegratorResponse $response,
		array $context,
	): ?McpException
	{
		if (
			$runId === null
			|| !self::isUnavailability($response)
			|| ActAsFailure::findMessage($response->getErrors()) !== null
		)
		{
			return null;
		}

		return $this->recordRunFailure($runId, $userId, $response->getErrors(), $context);
	}

	/**
	 * A call whose budget ran out before its next read ends as a report that did not answer, without the read:
	 * the AI service stops waiting on the tool soon after the budget, and the recipient has to learn why.
	 *
	 * @param array $context Log context of the read that is not made.
	 * @return McpException|null Null when the budget is not exhausted.
	 */
	public function findExhaustedBudget(
		?string $runId,
		int $userId,
		DigestCallBudget $budget,
		array $context,
	): ?McpException
	{
		if ($runId === null || !$budget->isExhausted())
		{
			return null;
		}

		return $this->recordRunFailure(
			$runId,
			$userId,
			[new Error('The time budget of the call is exhausted.')],
			$context,
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private function recordRunFailure(string $runId, int $userId, array $errors, array $context): McpException
	{
		AiToolsLogger::logWarning($errors, $context + ['runId' => $runId]);
		(new DigestToolFailureJournal())->record(
			$runId,
			$userId,
			DigestReason::DashboardTimeout,
			$this->attempt,
			$this->call,
		);

		return new McpException('The report is temporarily unavailable, do not retry.');
	}

	/**
	 * @param array<string, mixed> $readArguments
	 */
	private static function makeCallKey(string $toolName, array $readArguments): string
	{
		if ($readArguments === [])
		{
			return $toolName;
		}

		ksort($readArguments);

		return $toolName . ':' . Json::encode($readArguments);
	}

	/**
	 * A timeout comes back with no status at all, a frozen instance and a server failure with their own.
	 */
	private static function isUnavailability(IntegratorResponse $response): bool
	{
		$status = $response->getStatus();
		if ($status === IntegratorResponse::STATUS_OK)
		{
			return $response->hasErrors();
		}

		return !in_array($status, self::REQUEST_REFUSAL_STATUSES, true);
	}

	/**
	 * @return array<string, mixed>|null Null when nothing was pinned for this call.
	 * @throws McpException
	 */
	private function readPinnedContext(?RequestSubjectDto $subject): ?array
	{
		if ($subject === null || !array_key_exists(RequestSubjectDto::PINNED_CONTEXT_PARAM, $subject->payload))
		{
			return null;
		}

		// `null` under the key is refused along with every other non-string: the
		// envelope is signed by the portal itself, and its only writer
		// (CBPAiAssistantAgentActivity::fillPinnedContext()) casts an unfilled
		// property to an empty string and then omits the key. A null therefore means
		// an envelope that does not follow the format, not "nothing was pinned".
		$raw = $subject->payload[RequestSubjectDto::PINNED_CONTEXT_PARAM];
		if (!is_string($raw))
		{
			throw $this->unreadableContextException('pinned context is not a string');
		}

		// The envelope carries the value byte for byte, edges included.
		$raw = trim($raw);
		if ($raw === '')
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			throw $this->unreadableContextException('pinned context is not valid JSON');
		}

		if (!is_array($decoded) || !$this->isWellFormed($decoded))
		{
			throw $this->unreadableContextException('pinned context does not match the schema');
		}

		return $decoded;
	}

	private function isWellFormed(array $context): bool
	{
		$dashboardId = $context[PinnedContextPacker::KEY_DASHBOARD_ID] ?? null;
		if (!is_int($dashboardId) || $dashboardId <= 0)
		{
			return false;
		}

		$runId = $context[PinnedContextPacker::KEY_RUN_ID] ?? null;
		if (!is_string($runId) || $runId === '')
		{
			return false;
		}

		if (!$this->isScopeWellFormed($context[PinnedContextPacker::KEY_URL_PARAMS] ?? null))
		{
			return false;
		}

		// An absent key and a null mean different things here: no trimming happened
		// at all versus a period that failed to pack.
		return !array_key_exists(PinnedContextPacker::KEY_PERIOD, $context)
			|| $this->isPeriodWellFormed($context[PinnedContextPacker::KEY_PERIOD]);
	}

	private function isScopeWellFormed(mixed $scopeValues): bool
	{
		if (!is_array($scopeValues))
		{
			return false;
		}

		foreach ($scopeValues as $parameterCode => $value)
		{
			if (!is_string($parameterCode) || (!is_int($value) && !is_string($value)))
			{
				return false;
			}
		}

		return true;
	}

	private function isPeriodWellFormed(mixed $period): bool
	{
		if (!is_array($period))
		{
			return false;
		}

		foreach ([PinnedContextPacker::KEY_PERIOD_FROM, PinnedContextPacker::KEY_PERIOD_TO] as $bound)
		{
			$value = $period[$bound] ?? null;
			if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return string[] Argument names the tool declares in its input schema.
	 */
	private function getDeclaredArguments(ToolInterface $tool): array
	{
		$properties = $tool->getInputSchema()['properties'] ?? null;

		return is_array($properties) ? array_keys($properties) : [];
	}

	/**
	 * The whole period is replaced, not the first entry carrying it: the consumer
	 * of the resolved list keeps the last `period` it reads
	 * ({@see \Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters::convertToSupersetExtraFormData()}),
	 * so a second entry sent by the model would win over the pinned one.
	 * The pinned entry takes the place of the first one, so the order of the
	 * filters the model sent is kept.
	 *
	 * @param array<int, mixed>|mixed $filters Applied filters as the model sent them.
	 * @param array{from: string, to: string} $period
	 * @return array<int, mixed>
	 */
	private function applyPeriod(mixed $filters, array $period, string $toolName, string $runId): array
	{
		$filters = is_array($filters) ? array_values($filters) : [];

		$pinnedFilter = [
			self::ARGUMENT_FILTER_NAME => self::PERIOD_FILTER_NAME,
			self::ARGUMENT_FILTER_VALUE => $period,
		];

		$resolved = [];
		$sentPeriods = [];
		$isPinnedPlaced = false;
		foreach ($filters as $filter)
		{
			if (!is_array($filter) || ($filter[self::ARGUMENT_FILTER_NAME] ?? null) !== self::PERIOD_FILTER_NAME)
			{
				$resolved[] = $filter;

				continue;
			}

			$sentPeriods[] = $filter[self::ARGUMENT_FILTER_VALUE] ?? null;
			if (!$isPinnedPlaced)
			{
				$resolved[] = $pinnedFilter;
				$isPinnedPlaced = true;
			}
		}

		if (!$isPinnedPlaced)
		{
			$resolved[] = $pinnedFilter;
		}

		if ($sentPeriods !== [] && $sentPeriods !== [$period])
		{
			// One entry is reported as the value it carried; several are reported as
			// they came, because sending the period twice is itself the disagreement.
			$this->logOverride(
				self::PERIOD_FILTER_NAME,
				count($sentPeriods) === 1 ? $sentPeriods[0] : $sentPeriods,
				$period,
				$toolName,
				$runId,
			);
		}

		return $resolved;
	}

	/**
	 * A marker per disagreement, not per substitution: a value the model happened to
	 * get right is not a disagreement, and neither is a key it never sent. False
	 * markers would drown the signal that the agent prompt needs rewording.
	 */
	private function replace(
		array $target,
		string $key,
		mixed $value,
		string $field,
		string $toolName,
		string $runId,
	): array
	{
		if (array_key_exists($key, $target) && $target[$key] !== $value)
		{
			$this->logOverride($field, $target[$key], $value, $toolName, $runId);
		}

		$target[$key] = $value;

		return $target;
	}

	private function logOverride(
		string $field,
		mixed $sentByModel,
		mixed $pinned,
		string $toolName,
		string $runId,
	): void
	{
		AiToolsLogger::logInfo(self::OVERRIDE_MARKER, [
			'runId' => $runId,
			'tool' => $toolName,
			'field' => $field,
			'sentByModel' => $sentByModel,
			'pinned' => $pinned,
		]);
	}

	private function unreadableContextException(string $detail): McpException
	{
		AiToolsLogger::logErrors(
			[new Error($detail)],
			['stage' => 'pinned_context.read'],
		);

		return new McpException(
			'The pinned report context could not be read, so this report cannot be summarized. '
			. 'Report the failure instead of answering from another report.',
		);
	}
}
