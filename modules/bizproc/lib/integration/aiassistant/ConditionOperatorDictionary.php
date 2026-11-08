<?php

namespace Bitrix\Bizproc\Integration\AiAssistant;

use Bitrix\Bizproc\Activity\Enum\Operator;

/**
 * The comparison operators an AI agent may write into a condition, rendered as one text for the
 * settings schema.
 *
 * The list is closed but nothing rejects a code outside it: {@see \Bitrix\Bizproc\Activity\Condition::checkValue()}
 * routes an unknown operator to its `default` branch, so a misspelled code neither fails validation nor
 * fails at runtime - the condition just decides by the base comparison. That is why the dictionary has to
 * reach the agent instead of being guessed, and why the text says so out loud.
 *
 * Glosses are declared per enum case rather than taken from {@see \Bitrix\Bizproc\Activity\Condition::getOperatorList()}:
 * that list is localized for a human dialog and omits `modified`, while this text is a machine-facing
 * contract. A new operator without a gloss here is a failing test, not a silently shorter dictionary.
 */
final class ConditionOperatorDictionary
{
	/**
	 * @param array<int, Operator> $exclude operators the asking consumer cannot honour
	 * @return array<string, string> operator code => what it checks
	 */
	public static function map(array $exclude = []): array
	{
		$glosses = [
			Operator::Equal->name => 'equal to the value',
			Operator::NotEqual->name => 'not equal to the value',
			Operator::GreaterThen->name => 'greater than the value',
			Operator::GreaterThenOrEqual->name => 'greater than or equal to the value',
			Operator::LessThen->name => 'less than the value',
			Operator::LessThenOrEqual->name => 'less than or equal to the value',
			Operator::In->name => 'among the listed values (the value is a list of values)',
			Operator::NotIn->name => 'not among the listed values (the value is a list of values)',
			Operator::Contain->name => 'contains the value as a substring',
			Operator::NotContain->name => 'does not contain the value as a substring',
			Operator::Empty->name => 'has no value (the value part is not used)',
			Operator::NotEmpty->name => 'has any value (the value part is not used)',
			Operator::Between->name => 'within the range given as the value (a list of exactly two values, [from, to])',
			Operator::Modified->name => 'has changed (checked by the condition group itself, not by a comparison)',
		];

		$map = [];
		foreach (Operator::cases() as $operator)
		{
			// A case added to the enum without a gloss here is a hole in a machine-facing contract, and an
			// empty string would hide it inside the rendered text as `"code" - ` with nothing after the dash.
			if (!isset($glosses[$operator->name]))
			{
				continue;
			}

			if (in_array($operator, $exclude, true))
			{
				continue;
			}

			$map[$operator->value] = $glosses[$operator->name];
		}

		return $map;
	}

	/**
	 * One-line enumeration for embedding into a setting type description.
	 *
	 * @param array<int, Operator> $exclude operators the asking consumer cannot honour
	 */
	public static function text(array $exclude = []): string
	{
		$parts = [];
		foreach (self::map($exclude) as $code => $gloss)
		{
			$parts[] = '"' . $code . '" - ' . $gloss;
		}

		return implode('; ', $parts);
	}

	/**
	 * The enumeration together with the warning that nothing enforces it - the whole contract in one sentence
	 * pair, so every place that publishes the operators publishes the same words. Three hand-written variants
	 * of this warning had already appeared, and a wording that drifts is a contract that drifts.
	 *
	 * An operator the consumer cannot honour is left out rather than published with a caveat: the same silence
	 * applies to it. Both consumers that narrow the list today leave out `modified`, and for different reasons -
	 * the storage filter drops such a line and widens the selection, while a rule condition runs the operator
	 * against the current value of the field and is therefore false always - so the only safe contract is one the
	 * agent cannot write that operator into.
	 *
	 * @param string $subject what a misspelled operator sends the wrong way, e.g. 'the condition', 'the branch'
	 * @param array<int, Operator> $exclude operators this consumer cannot honour
	 */
	public static function contractText(string $subject, array $exclude = []): string
	{
		return
			'The operator is one of: ' . self::text($exclude) . '.'
			. ' Nothing rejects an operator outside this list - neither saving nor running the process - so a'
			. ' misspelled code silently makes ' . $subject . ' decide the wrong way. Use a code from the list'
			. ' verbatim.'
		;
	}
}
