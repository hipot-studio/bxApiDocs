<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Mobile\Internal\Entity\Marketing\Choice;
use Bitrix\Mobile\Internal\Entity\Marketing\Condition;
use Bitrix\Mobile\Internal\Entity\Marketing\ConditionGroup;
use Bitrix\Mobile\Internal\Entity\Marketing\EvaluationContext;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\RuleDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\RuleEvaluation;
use Bitrix\Mobile\Internal\Entity\Marketing\SelectionResult;
use Bitrix\Mobile\Internal\Entity\Marketing\Verdict;

/**
 * Evaluates rules of the registry for a user or a pack, lazily and in three values.
 *
 * A pack goes condition by condition rather than user by user: each condition asks its source once for
 * every user still undecided, and a user whose outcome is known leaves the reads that follow. Inside a
 * group the facts of the portal go before the facts of the user, each in declared order; "all of" stops
 * at the first not matched, "any of" at the first matched, and an unknown keeps reading, since a later
 * fact may still decide. A choice takes its first matched alternative, passes the turn on a not matched
 * one and stops on an unknown.
 *
 * A fact is read once per call for all the rules that ask for it, keyed by its code and the parameters of
 * the fact; the parameters of the operator never reach the source, so a source cannot decide. Nothing is
 * kept between calls and nothing is written.
 *
 * A source that throws is not a fact nobody knows: every user of that read - every user of the call for a
 * fact of the portal - is failed. The condition is unknown for them whatever it folds an unknown into, and
 * an evaluation the failed fact leaves unknown is marked failed; one the other facts decide stays decided.
 * The failure is written down once, and the evaluation of the call goes on. A source that fails only some
 * users of the read throws PartialReadException: only they are failed, each written down in a line.
 */
final class Evaluator
{
	public const DATA_SELECTION = 'selection';

	/**
	 * The largest pack a call judges. Rights are read per user, so the cost of a call grows with the pack;
	 * a caller with more users asks in packs of this size. It is a multiple of the portion of 500 the
	 * sources read their users in, so asking in packs costs a source no extra query.
	 */
	public const MAX_PACK_SIZE = 1000;

	public const ERROR_EMPTY_PACK = 'MARKETING_EMPTY_PACK';
	public const ERROR_INVALID_USER = 'MARKETING_INVALID_USER';
	public const ERROR_PACK_TOO_LARGE = 'MARKETING_PACK_TOO_LARGE';
	public const ERROR_UNKNOWN_CHANNEL = 'MARKETING_UNKNOWN_CHANNEL';
	public const ERROR_UNKNOWN_PLACEMENT = 'MARKETING_UNKNOWN_PLACEMENT';
	public const ERROR_UNKNOWN_RULE = 'MARKETING_UNKNOWN_RULE';
	public const ERROR_RULE_OUTSIDE_CONTEXT = 'MARKETING_RULE_OUTSIDE_CONTEXT';

	private const OPERATOR_PARAMS = [Condition::PARAM_VALUES, Condition::PARAM_FROM, Condition::PARAM_TO];

	private readonly OperatorEvaluator $operators;
	private readonly SelectionLog $log;

	/**
	 * Values read during the current call: read key => userId => value, 0 standing for the portal.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $values = [];

	/**
	 * Reads of the current call whose source threw: read key => userId => true, 0 standing for the portal.
	 *
	 * @var array<string, array<int, true>>
	 */
	private array $failures = [];

	public function __construct(
		private readonly Registry $registry,
		?OperatorEvaluator $operators = null,
		?SelectionLog $log = null,
	)
	{
		$this->operators = $operators ?? new OperatorEvaluator();
		$this->log = $log ?? new SelectionLog();
	}

	/**
	 * @param string[] $ruleCodes rules to evaluate; empty - every rule of the channel and the placement
	 * @return Result with the SelectionResult under DATA_SELECTION, or the errors of the whole call
	 */
	public function evaluate(EvaluationContext $context, array $ruleCodes = []): Result
	{
		$result = new Result();

		$errors = $this->checkContext($context);
		if ($errors === [])
		{
			[$rules, $errors] = $this->resolveRules($context, $ruleCodes);
		}

		if ($errors !== [])
		{
			return $result->addErrors($errors);
		}

		try
		{
			$evaluations = array_fill_keys($context->userIds, []);
			foreach ($rules as $rule)
			{
				foreach ($this->evaluateRule($rule, $context) as $userId => $evaluation)
				{
					$evaluations[$userId][] = $evaluation;
				}
			}
		}
		finally
		{
			$this->values = [];
			$this->failures = [];
		}

		return $result->setData([self::DATA_SELECTION => new SelectionResult($evaluations)]);
	}

	/**
	 * @return Error[]
	 */
	private function checkContext(EvaluationContext $context): array
	{
		$errors = [];
		if ($context->userIds === [])
		{
			$errors[] = new Error('The pack of users is empty', self::ERROR_EMPTY_PACK);
		}

		$size = count($context->userIds);
		if ($size > self::MAX_PACK_SIZE)
		{
			$errors[] = new Error(
				'The pack of users is larger than ' . self::MAX_PACK_SIZE,
				self::ERROR_PACK_TOO_LARGE,
				['size' => $size, 'limit' => self::MAX_PACK_SIZE],
			);
		}

		$invalid = array_values(array_filter($context->userIds, static fn(int $userId): bool => $userId <= 0));
		if ($invalid !== [])
		{
			$errors[] = new Error(
				'User identifiers must be positive',
				self::ERROR_INVALID_USER,
				['userIds' => $invalid],
			);
		}

		if (!$this->registry->hasChannel($context->channel))
		{
			$errors[] = new Error(
				'Channel "' . $context->channel . '" has no rules',
				self::ERROR_UNKNOWN_CHANNEL,
				['channel' => $context->channel],
			);
		}
		elseif ($context->placement !== null && !$this->registry->hasPlacement($context->channel, $context->placement))
		{
			$errors[] = new Error(
				'Placement "' . $context->placement . '" is not known in channel "' . $context->channel . '"',
				self::ERROR_UNKNOWN_PLACEMENT,
				['channel' => $context->channel, 'placement' => $context->placement],
			);
		}

		return $errors;
	}

	/**
	 * @param string[] $ruleCodes
	 * @return array{0: RuleDefinition[], 1: Error[]}
	 */
	private function resolveRules(EvaluationContext $context, array $ruleCodes): array
	{
		$acting = $this->registry->getRulesFor($context->channel, $context->placement);
		if ($ruleCodes === [])
		{
			return [$acting, []];
		}

		$rules = [];
		$errors = [];
		foreach ($ruleCodes as $code)
		{
			$rule = $this->registry->getRule($code);
			if ($rule === null)
			{
				$errors[] = new Error(
					'Rule "' . $code . '" is not registered',
					self::ERROR_UNKNOWN_RULE,
					['rule' => $code],
				);
			}
			elseif (!in_array($rule, $acting, true))
			{
				$errors[] = new Error(
					'Rule "' . $code . '" does not act in the channel and the placement asked about',
					self::ERROR_RULE_OUTSIDE_CONTEXT,
					['rule' => $code],
				);
			}
			else
			{
				$rules[] = $rule;
			}
		}

		return [$rules, $errors];
	}

	/**
	 * The choice is judged only for the users the conditions of the rule admit. A user they leave unknown
	 * stays unknown without it, although a choice that matches nothing would make the whole not matched
	 * (ADR, items 3-4): the reads of the choice are saved on purpose. No rule of today loses by it - the
	 * activation of the pushes has no conditions besides its choice, and the choice of 703508 never matches
	 * nothing, since its last step has no source and stays unknown.
	 *
	 * @return array<int, RuleEvaluation>
	 */
	private function evaluateRule(RuleDefinition $rule, EvaluationContext $context): array
	{
		[$verdicts, $failed] = $this->evaluateGroup($rule->conditions, $context->userIds, $context);
		$chosen = array_fill_keys($context->userIds, []);

		$admitted = self::usersWith($verdicts, Verdict::Matched);
		if ($rule->choice !== null && $admitted !== [])
		{
			[$choiceVerdicts, $choiceChosen, $choiceFailed] = $this->evaluateChoice($rule->choice, $admitted, $context);
			$verdicts = array_replace($verdicts, $choiceVerdicts);
			$chosen = array_replace($chosen, $choiceChosen);
			$failed += $choiceFailed;
		}

		$evaluations = [];
		foreach ($context->userIds as $userId)
		{
			$verdict = $verdicts[$userId];
			$evaluations[$userId] = new RuleEvaluation(
				$rule->code,
				$verdict,
				$verdict === Verdict::Matched ? $chosen[$userId] : [],
				clone $context->now,
				isset($failed[$userId]),
			);
		}

		return $evaluations;
	}

	/**
	 * @param int[] $userIds
	 * @return array{0: array<int, Verdict>, 1: array<int, array<string, string>>, 2: array<int, true>}
	 *     the verdicts, the alternatives chosen and the users a failed read left unknown
	 */
	private function evaluateChoice(Choice $choice, array $userIds, EvaluationContext $context): array
	{
		$verdicts = [];
		$chosen = array_fill_keys($userIds, []);
		$failed = [];
		$open = $userIds;

		foreach ($choice->alternatives as $code => $conditions)
		{
			[$alternativeVerdicts, $alternativeFailed] = $this->evaluateGroup($conditions, $open, $context);
			foreach (self::usersWith($alternativeVerdicts, Verdict::Unknown) as $userId)
			{
				$verdicts[$userId] = Verdict::Unknown;
			}
			$failed += $alternativeFailed;

			$picked = self::usersWith($alternativeVerdicts, Verdict::Matched);
			foreach ($picked as $userId)
			{
				$chosen[$userId][$choice->name] = $code;
				$verdicts[$userId] = Verdict::Matched;
			}

			$nested = $choice->next[$code] ?? null;
			if ($nested !== null && $picked !== [])
			{
				[$nestedVerdicts, $nestedChosen, $nestedFailed] = $this->evaluateChoice($nested, $picked, $context);
				$verdicts = array_replace($verdicts, $nestedVerdicts);
				$failed += $nestedFailed;
				foreach ($picked as $userId)
				{
					$chosen[$userId] += $nestedChosen[$userId];
				}
			}

			$open = self::usersWith($alternativeVerdicts, Verdict::NotMatched);
		}

		foreach ($open as $userId)
		{
			$verdicts[$userId] = Verdict::NotMatched;
		}

		return [$verdicts, $chosen, $failed];
	}

	/**
	 * An unknown of the group is failed once a failed read took part in it: its fact could have decided.
	 *
	 * @param int[] $userIds
	 * @return array{0: array<int, Verdict>, 1: array<int, true>} the verdicts and the users a failed read
	 *     left unknown
	 */
	private function evaluateGroup(ConditionGroup $group, array $userIds, EvaluationContext $context): array
	{
		$decisive = $group->isAnyOf() ? Verdict::Matched : Verdict::NotMatched;
		$collected = array_fill_keys($userIds, []);
		$touchedByFailure = [];
		$open = $userIds;

		foreach ($this->inReadingOrder($group) as $item)
		{
			[$itemVerdicts, $itemFailed] = $item instanceof ConditionGroup
				? $this->evaluateGroup($item, $open, $context)
				: $this->evaluateCondition($item, $open, $context)
			;
			$touchedByFailure += $itemFailed;

			$stillOpen = [];
			foreach ($open as $userId)
			{
				$collected[$userId][] = $itemVerdicts[$userId];
				if ($itemVerdicts[$userId] !== $decisive)
				{
					$stillOpen[] = $userId;
				}
			}
			$open = $stillOpen;
		}

		$verdicts = array_map(
			static fn(array $userVerdicts): Verdict => $group->isAnyOf()
				? Verdict::anyOf(...$userVerdicts)
				: Verdict::allOf(...$userVerdicts),
			$collected,
		);
		$failed = array_intersect_key($touchedByFailure, array_flip(self::usersWith($verdicts, Verdict::Unknown)));

		return [$verdicts, $failed];
	}

	/**
	 * @param int[] $userIds
	 * @return array{0: array<int, Verdict>, 1: array<int, true>} the verdicts and the users whose read failed
	 */
	private function evaluateCondition(Condition $condition, array $userIds, EvaluationContext $context): array
	{
		if ($condition->isEmpty())
		{
			return [array_fill_keys($userIds, Verdict::Matched), []];
		}

		$fact = $this->registry->getFact($condition->factCode);
		if ($fact === null)
		{
			return [array_fill_keys($userIds, Verdict::Unknown), []];
		}

		[$values, $failed] = $this->read($condition, $fact, $userIds, $context);

		$verdicts = [];
		foreach ($userIds as $userId)
		{
			$verdicts[$userId] = isset($failed[$userId])
				? Verdict::Unknown
				: $this->operators->apply($condition, $fact, $values[$userId])
			;
		}

		return [$verdicts, $failed];
	}

	/**
	 * Asks the source only about the users whose value this call has neither read nor failed to read; a
	 * fact of the portal is read once, under 0.
	 *
	 * @param int[] $userIds
	 * @return array{0: array<int, mixed>, 1: array<int, true>} userId => value, null for unknown, and the
	 *     users whose read failed
	 */
	private function read(Condition $condition, FactDefinition $fact, array $userIds, EvaluationContext $context): array
	{
		$params = array_diff_key($condition->params, array_flip(self::OPERATOR_PARAMS));
		$key = $condition->factCode . ':' . serialize(self::comparable($params));
		$known = $this->values[$key] ?? [];
		$failures = $this->failures[$key] ?? [];

		$isAsked = static fn(int $id): bool => array_key_exists($id, $known) || isset($failures[$id]);
		$missing = $fact->perUser
			? array_values(array_filter($userIds, static fn(int $userId): bool => !$isAsked($userId)))
			: ($isAsked(0) ? [] : $userIds)
		;

		$source = $this->registry->getSource($condition->factCode);
		if ($missing !== [] && $source !== null)
		{
			$ids = $fact->perUser ? $missing : [0];
			try
			{
				$answer = $source->read($condition->factCode, $params, $context, $missing);
				foreach ($ids as $id)
				{
					$known[$id] = $answer[$id] ?? null;
				}
				$this->values[$key] = $known;
			}
			catch (PartialReadException $partial)
			{
				$answer = $partial->getValues();
				$failedIds = [];
				$firstCause = null;
				foreach ($ids as $id)
				{
					$cause = $partial->getFailures()[$id] ?? null;
					if ($cause === null)
					{
						$known[$id] = $answer[$id] ?? null;

						continue;
					}

					$failedIds[] = $id;
					$firstCause ??= $cause;
					$failures[$id] = true;
				}
				if ($firstCause !== null)
				{
					$this->log->write(
						'Marketing selection: the fact "' . $condition->factCode . '" could not be read for '
						. count($failedIds) . ' users by ' . get_debug_type($source) . ', first for user '
						. $failedIds[0] . ' - ' . SelectionLog::describe($firstCause),
					);
				}
				$this->values[$key] = $known;
				$this->failures[$key] = $failures;
			}
			catch (\Throwable $throwable)
			{
				$this->log->write(
					'Marketing selection: the fact "' . $condition->factCode . '" could not be read for '
					. count($missing) . ' users by ' . get_debug_type($source) . ' - '
					. SelectionLog::describe($throwable),
				);
				$failures += array_fill_keys($ids, true);
				$this->failures[$key] = $failures;
			}
		}

		$values = [];
		$failed = [];
		foreach ($userIds as $userId)
		{
			$id = $fact->perUser ? $userId : 0;
			if (isset($failures[$id]))
			{
				$failed[$userId] = true;
			}
			$values[$userId] = $known[$id] ?? null;
		}

		return [$values, $failed];
	}

	/**
	 * Conditions on facts of the portal, and groups made of nothing else, go first, then the rest, each
	 * part in declared order: the verdict does not depend on the order, the cost does.
	 *
	 * @return array<Condition|ConditionGroup>
	 */
	private function inReadingOrder(ConditionGroup $group): array
	{
		$portal = [];
		$user = [];
		foreach ($group->getItems() as $item)
		{
			if ($this->isAboutUsers($item))
			{
				$user[] = $item;
			}
			else
			{
				$portal[] = $item;
			}
		}

		return [...$portal, ...$user];
	}

	private function isAboutUsers(Condition|ConditionGroup $item): bool
	{
		if ($item instanceof Condition)
		{
			return $this->registry->getFact($item->factCode)?->perUser ?? true;
		}

		foreach ($item->getItems() as $nested)
		{
			if ($this->isAboutUsers($nested))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, Verdict> $verdicts
	 * @return int[]
	 */
	private static function usersWith(array $verdicts, Verdict $verdict): array
	{
		return array_keys(array_filter($verdicts, static fn(Verdict $userVerdict): bool => $userVerdict === $verdict));
	}

	/**
	 * Parameters of a fact as a key of the read: a date counts by its class and its moment, not by its
	 * object - a source may take a date and a date with time of one moment differently.
	 */
	private static function comparable(array $params): array
	{
		ksort($params);

		return array_map(
			static fn(mixed $value): mixed => match (true)
			{
				$value instanceof Date => ['class' => $value::class, 'moment' => $value->getTimestamp()],
				is_array($value) => self::comparable($value),
				default => $value,
			},
			$params,
		);
	}
}
