<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Mobile\Internal\Entity\Marketing\Choice;
use Bitrix\Mobile\Internal\Entity\Marketing\Condition;
use Bitrix\Mobile\Internal\Entity\Marketing\ConditionGroup;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\FactType;
use Bitrix\Mobile\Internal\Entity\Marketing\Operator;
use Bitrix\Mobile\Internal\Entity\Marketing\RuleDefinition;
use Psr\Container\ContainerInterface;

/**
 * Facts and rules by their stable codes. A definition is judged once, when it is plugged in: a fact
 * code belongs to the first source that declares it, and a rule is accepted only when every fact it
 * names is registered and serves its channel, with an operator that fits the type of the fact and
 * parameters of that type.
 * A wrong definition is turned away alone, reported in the Result, and changes nothing registered; the
 * sources and sets of the configuration have their refusals written down in the journal as well. A
 * source or a set that throws while it declares itself, or that the container throws on while building
 * it, is turned away whole and written down in the journal, and the others are plugged in as usual.
 */
final class Registry
{
	public const ERROR_INVALID_DEFINITION = 'MARKETING_INVALID_DEFINITION';
	public const ERROR_DEFINITION_FAILED = 'MARKETING_DEFINITION_FAILED';
	public const ERROR_DUPLICATE_FACT = 'MARKETING_DUPLICATE_FACT';
	public const ERROR_DUPLICATE_RULE = 'MARKETING_DUPLICATE_RULE';
	public const ERROR_UNKNOWN_FACT = 'MARKETING_UNKNOWN_FACT';
	public const ERROR_OPERATOR_MISMATCH = 'MARKETING_OPERATOR_MISMATCH';
	public const ERROR_UNDECLARED_VALUE = 'MARKETING_UNDECLARED_VALUE';
	public const ERROR_INVALID_PARAMS = 'MARKETING_INVALID_PARAMS';
	public const ERROR_FACT_OUTSIDE_CHANNEL = 'MARKETING_FACT_OUTSIDE_CHANNEL';

	private const MODULE_ID = 'mobile';
	private const CONFIGURATION_SECTION = 'marketing';

	/** @var array<string, FactDefinition> */
	private array $facts = [];

	/** @var array<string, FactSource> */
	private array $sources = [];

	/** @var array<string, RuleDefinition> */
	private array $rules = [];

	private readonly SelectionLog $log;

	public function __construct(?SelectionLog $log = null)
	{
		$this->log = $log ?? new SelectionLog();
	}

	/**
	 * The section names sources under "facts" and rule sets under "rules" by class, and the container
	 * builds them; facts go first, since rules are judged against them. An entry that is not a source
	 * or a set is skipped, and one the container cannot build is skipped and written down.
	 *
	 * @param array|null $section the "marketing" section of the module configuration when null
	 */
	public static function fromConfiguration(
		ContainerInterface $services,
		?array $section = null,
		?SelectionLog $log = null,
	): self
	{
		$section ??= Configuration::getInstance(self::MODULE_ID)->get(self::CONFIGURATION_SECTION);
		$section = is_array($section) ? $section : [];

		$registry = new self($log);
		foreach ($registry->build($section['facts'] ?? [], FactSource::class, $services) as $source)
		{
			$registry->writeDownRefusals($source, $registry->addFactSource($source));
		}

		foreach ($registry->build($section['rules'] ?? [], RuleSet::class, $services) as $ruleSet)
		{
			$registry->writeDownRefusals($ruleSet, $registry->addRuleSet($ruleSet));
		}

		return $registry;
	}

	/**
	 * Writes down the definitions a result of plugging in turned away, a line for each, so a rule missing
	 * later is traced to its cause. A source or a set that threw is written down already when turned away.
	 */
	public function writeDownRefusals(FactSource|RuleSet $definitions, Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === self::ERROR_DEFINITION_FAILED)
			{
				continue;
			}

			$this->log->write(
				'Marketing selection: ' . get_debug_type($definitions) . ' - turned away: ' . $error->getMessage(),
			);
		}
	}

	public function addFactSource(FactSource $source): Result
	{
		$result = new Result();
		try
		{
			$definitions = $source->getDefinitions();
		}
		catch (\Throwable $throwable)
		{
			return $result->addError($this->turnAway($source, 'cannot declare its facts', $throwable));
		}

		foreach ($definitions as $definition)
		{
			if (!$definition instanceof FactDefinition)
			{
				$result->addError(new Error(
					get_debug_type($source) . ' declares something that is not a fact',
					self::ERROR_INVALID_DEFINITION,
				));

				continue;
			}

			if (isset($this->facts[$definition->code]))
			{
				$result->addError(new Error(
					'Fact "' . $definition->code . '" is already registered',
					self::ERROR_DUPLICATE_FACT,
					['fact' => $definition->code],
				));

				continue;
			}

			$this->facts[$definition->code] = $definition;
			$this->sources[$definition->code] = $source;
		}

		return $result;
	}

	public function addRuleSet(RuleSet $ruleSet): Result
	{
		$result = new Result();
		try
		{
			$rules = $ruleSet->getRules();
		}
		catch (\Throwable $throwable)
		{
			return $result->addError($this->turnAway($ruleSet, 'cannot give its rules', $throwable));
		}

		foreach ($rules as $rule)
		{
			if (!$rule instanceof RuleDefinition)
			{
				$result->addError(new Error(
					get_debug_type($ruleSet) . ' gives something that is not a rule',
					self::ERROR_INVALID_DEFINITION,
				));

				continue;
			}

			$error = $this->checkRule($rule);
			if ($error !== null)
			{
				$result->addError($error);

				continue;
			}

			$this->rules[$rule->code] = $rule;
		}

		return $result;
	}

	public function getRule(string $code): ?RuleDefinition
	{
		return $this->rules[$code] ?? null;
	}

	/**
	 * @return RuleDefinition[] in registration order: the rules of the channel acting in the placement,
	 *     including those bound to no placement; every rule of the channel when no placement is given
	 */
	public function getRulesFor(string $channel, ?string $placement): array
	{
		return array_values(array_filter(
			$this->rules,
			static fn(RuleDefinition $rule): bool => $rule->channel === $channel
				&& ($placement === null || $rule->placements === [] || in_array($placement, $rule->placements, true)),
		));
	}

	public function getFact(string $code): ?FactDefinition
	{
		return $this->facts[$code] ?? null;
	}

	public function getSource(string $factCode): ?FactSource
	{
		return $this->sources[$factCode] ?? null;
	}

	public function hasChannel(string $channel): bool
	{
		foreach ($this->rules as $rule)
		{
			if ($rule->channel === $channel)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A placement is known once a rule of the channel names it; a rule bound to no placement names none.
	 */
	public function hasPlacement(string $channel, string $placement): bool
	{
		foreach ($this->rules as $rule)
		{
			if ($rule->channel === $channel && in_array($placement, $rule->placements, true))
			{
				return true;
			}
		}

		return false;
	}

	private function checkRule(RuleDefinition $rule): ?Error
	{
		if (isset($this->rules[$rule->code]))
		{
			return new Error(
				'Rule "' . $rule->code . '" is already registered',
				self::ERROR_DUPLICATE_RULE,
				['rule' => $rule->code],
			);
		}

		if (!self::hasOnlyGroupAlternatives($rule->choice))
		{
			return new Error(
				'Rule "' . $rule->code . '" has an alternative that is not a group of conditions',
				self::ERROR_INVALID_DEFINITION,
				['rule' => $rule->code],
			);
		}

		$conditions = [...self::collectConditions($rule->conditions), ...self::collectChoiceConditions($rule->choice)];
		foreach ($conditions as $condition)
		{
			$error = $this->checkCondition($condition, $rule);
			if ($error !== null)
			{
				return $error;
			}
		}

		return null;
	}

	private function checkCondition(Condition $condition, RuleDefinition $rule): ?Error
	{
		$ruleCode = $rule->code;
		$fact = $this->facts[$condition->factCode] ?? null;
		if ($fact === null)
		{
			return self::conditionError(self::ERROR_UNKNOWN_FACT, 'the fact is not registered', $condition, $ruleCode);
		}

		if ($fact->channel !== null && $fact->channel !== $rule->channel)
		{
			return self::conditionError(
				self::ERROR_FACT_OUTSIDE_CHANNEL,
				'the fact serves the rules of channel "' . $fact->channel . '" only',
				$condition,
				$ruleCode,
			);
		}

		if (!self::fits($condition->operator, $fact->type))
		{
			return self::conditionError(
				self::ERROR_OPERATOR_MISMATCH,
				$condition->operator->name . ' does not fit a fact of type ' . $fact->type->name,
				$condition,
				$ruleCode,
			);
		}

		return match ($condition->operator)
		{
			Operator::InSet, Operator::Enumeration => self::checkValues($condition, $fact, $ruleCode),
			Operator::Range => self::checkBounds(
				$condition,
				static fn(mixed $bound): bool => is_int($bound) || is_float($bound),
				$ruleCode,
			),
			Operator::DateRange => self::checkBounds(
				$condition,
				static fn(mixed $bound): bool => $bound instanceof Date,
				$ruleCode,
			),
		};
	}

	/**
	 * An enumeration names only values its fact declares, and so does a set of a fact that declares its
	 * values; a set of an open fact names any value of its type.
	 */
	private static function checkValues(Condition $condition, FactDefinition $fact, string $ruleCode): ?Error
	{
		$values = $condition->params[Condition::PARAM_VALUES] ?? [];
		if (!is_array($values))
		{
			return self::conditionError(self::ERROR_INVALID_PARAMS, 'the values are not a list', $condition, $ruleCode);
		}

		$mustBeDeclared = $condition->operator === Operator::Enumeration
			|| ($fact->type === FactType::EnumValue && $fact->allowedValues !== [])
		;
		foreach ($values as $value)
		{
			if (!self::isValueOf($fact->type, $value))
			{
				return self::conditionError(
					self::ERROR_INVALID_PARAMS,
					'a value is not of type ' . $fact->type->name,
					$condition,
					$ruleCode,
				);
			}

			if ($mustBeDeclared && !in_array($value, $fact->allowedValues, true))
			{
				return self::conditionError(
					self::ERROR_UNDECLARED_VALUE,
					'the value "' . $value . '" is not declared by the fact',
					$condition,
					$ruleCode,
				);
			}
		}

		return null;
	}

	/**
	 * @param \Closure(mixed): bool $isBound
	 */
	private static function checkBounds(Condition $condition, \Closure $isBound, string $ruleCode): ?Error
	{
		$from = $condition->params[Condition::PARAM_FROM] ?? null;
		$to = $condition->params[Condition::PARAM_TO] ?? null;

		foreach ([$from, $to] as $bound)
		{
			if ($bound !== null && !$isBound($bound))
			{
				return self::conditionError(
					self::ERROR_INVALID_PARAMS,
					'a bound does not fit the operator',
					$condition,
					$ruleCode,
				);
			}
		}

		if ($from !== null && $to !== null && self::position($from) > self::position($to))
		{
			return self::conditionError(
				self::ERROR_INVALID_PARAMS,
				'the range ends before it starts',
				$condition,
				$ruleCode,
			);
		}

		return null;
	}

	private static function fits(Operator $operator, FactType $type): bool
	{
		return match ($operator)
		{
			Operator::InSet => $type !== FactType::Date,
			Operator::Range => $type === FactType::Number,
			Operator::DateRange => $type === FactType::Date,
			Operator::Enumeration => $type === FactType::EnumValue,
		};
	}

	private static function isValueOf(FactType $type, mixed $value): bool
	{
		return match ($type)
		{
			FactType::Boolean => is_bool($value),
			FactType::Number => is_int($value) || is_float($value),
			FactType::Date => $value instanceof Date,
			FactType::EnumValue, FactType::StringList => is_string($value),
		};
	}

	private static function position(int|float|Date $bound): int|float
	{
		return $bound instanceof Date ? $bound->getTimestamp() : $bound;
	}

	private static function conditionError(string $code, string $problem, Condition $condition, string $ruleCode): Error
	{
		return new Error(
			'Rule "' . $ruleCode . '", fact "' . $condition->factCode . '": ' . $problem,
			$code,
			['rule' => $ruleCode, 'fact' => $condition->factCode],
		);
	}

	private static function hasOnlyGroupAlternatives(?Choice $choice): bool
	{
		if ($choice === null)
		{
			return true;
		}

		foreach ($choice->alternatives as $alternative)
		{
			if (!$alternative instanceof ConditionGroup)
			{
				return false;
			}
		}

		foreach ($choice->next as $nested)
		{
			if (!self::hasOnlyGroupAlternatives($nested))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return Condition[]
	 */
	private static function collectConditions(ConditionGroup $group): array
	{
		$conditions = [];
		foreach ($group->getItems() as $item)
		{
			array_push($conditions, ...($item instanceof ConditionGroup ? self::collectConditions($item) : [$item]));
		}

		return $conditions;
	}

	/**
	 * @return Condition[]
	 */
	private static function collectChoiceConditions(?Choice $choice): array
	{
		if ($choice === null)
		{
			return [];
		}

		$conditions = [];
		foreach ($choice->alternatives as $alternative)
		{
			array_push($conditions, ...self::collectConditions($alternative));
		}

		foreach ($choice->next as $nested)
		{
			array_push($conditions, ...self::collectChoiceConditions($nested));
		}

		return $conditions;
	}

	/**
	 * @template T of object
	 * @param class-string<T> $contract
	 * @return T[]
	 */
	private function build(mixed $classNames, string $contract, ContainerInterface $services): array
	{
		if (!is_array($classNames))
		{
			return [];
		}

		$built = [];
		foreach ($classNames as $className)
		{
			if (!is_string($className) || !is_a($className, $contract, true))
			{
				continue;
			}

			try
			{
				$built[] = $services->get($className);
			}
			catch (\Throwable $throwable)
			{
				$this->log->write(
					'Marketing selection: ' . $className . ' cannot be built - ' . SelectionLog::describe($throwable),
				);
			}
		}

		return $built;
	}

	private function turnAway(FactSource|RuleSet $definitions, string $problem, \Throwable $throwable): Error
	{
		$className = get_debug_type($definitions);
		$this->log->write(
			'Marketing selection: ' . $className . ' ' . $problem . ' - ' . SelectionLog::describe($throwable),
		);

		return new Error(
			$className . ' ' . $problem . ': ' . SelectionLog::shorten($throwable->getMessage()),
			self::ERROR_DEFINITION_FAILED,
			['class' => $className],
		);
	}
}
