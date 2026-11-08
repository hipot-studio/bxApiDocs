<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Bizproc\Integration\AiAssistant\ConditionOperatorDictionary;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\BizprocDesigner\Internal\Entity\ComplexBlockDetail;
use Bitrix\BizprocDesigner\Internal\Entity\ComplexNodeAction;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The `typesDescription` map of one block of the agent catalog.
 *
 * The flat settings of the block are only part of it. A complex node keeps most of what an agent fills in
 * out of that list:
 *
 * - the settings of its sub-actions live in their own schemas ({@see ComplexNodeAction::$settingsSchema}), so
 *   a described type used only there - a `user` inside "create a task", say - never reached the agent at all:
 *   it was told to name a person without being told how a person is named;
 * - the `condition` of a rule is not a setting and has no type to carry a description, yet it is written by
 *   hand exactly like one. Its operator is the part nothing checks: an unknown code is rejected neither on
 *   save nor at run time ({@see \Bitrix\Bizproc\Activity\Condition::checkValue()}), so the process just decides
 *   the wrong way. The dictionary is published under the construction's own name because the construction's
 *   shape is its own - a projection of `mixedcondition`, not the stored shape of the `conditiongroup` type.
 */
final class BlockDescribedTypesResolver
{
	public const CONDITION_CONSTRUCTION_KEY = 'condition';

	private const LOGGER_ID = 'bizprocdesigner.aiassistant.block_catalog';

	private ?LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger;
	}

	/**
	 * @return array<string, string> [type => description, ...]
	 */
	public function resolve(SettingCollection $settings, ?ComplexBlockDetail $complexDetail): array
	{
		$types = $settings->getDescribedSettingTypesMap();

		if ($complexDetail === null)
		{
			return $types;
		}

		foreach ($complexDetail->nodeActions as $nodeAction)
		{
			foreach ($nodeAction->settingsSchema->getDescribedSettingTypesMap() as $type => $description)
			{
				$this->addDescribedType($types, (string)$type, $description, $nodeAction->activityCode);
			}
		}

		// A rule condition is only described where the node takes one: its block composition decides whether the
		// rules accept a condition at all, and describing a construction the save path refuses invites the agent
		// to write it.
		if ($complexDetail->conditionSupported)
		{
			$types[self::CONDITION_CONSTRUCTION_KEY] = $this->makeConditionDescription();
		}

		return $types;
	}

	/**
	 * One name of a type carries one description, so the first one wins and the map stays single-valued: the
	 * agent reads it as the contract of the type and two descriptions of one name would contradict each other.
	 *
	 * Which is exactly why a second, different description is not dropped quietly. A type name is described by
	 * the class of the base type ({@see \Bitrix\Bizproc\BaseType\Base::getAiSettingType()}), the same answer for
	 * every setting of that type, so two sub-actions disagreeing about one name is a defect of the descriptions
	 * and not a case to be resolved here. It is reported instead of being resolved.
	 *
	 * @param array<string, string> $types
	 */
	private function addDescribedType(array &$types, string $type, string $description, string $subAction): void
	{
		$known = $types[$type] ?? null;
		if ($known === null)
		{
			$types[$type] = $description;

			return;
		}

		if ($known !== $description)
		{
			$this->getLogger()->warning(
				'AI block catalog: type "{type}" is described in two ways, the published one wins.'
				. ' Published: {published}. Discarded, from sub-action {subAction}: {discarded}',
				[
					'type' => $type,
					'subAction' => $subAction,
					'published' => $known,
					'discarded' => $description,
				],
			);
		}
	}

	/**
	 * `modified` is the operator of a change event: only {@see \CBPFieldCondition} and the triggers hand the
	 * condition group the list of changed fields. A rule runs through {@see \CBPMixedCondition}, which passes the
	 * current value of the field instead, so the operator would compare the wrong things without saying so.
	 */
	private function makeConditionDescription(): string
	{
		return 'This is the "operator" of a rule condition. '
			. ConditionOperatorDictionary::contractText('the rule', [Operator::Modified])
		;
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}
}
