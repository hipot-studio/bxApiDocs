<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing\Rule;

use Bitrix\Mobile\Internal\Entity\Marketing\Choice;
use Bitrix\Mobile\Internal\Entity\Marketing\Condition;
use Bitrix\Mobile\Internal\Entity\Marketing\ConditionGroup;
use Bitrix\Mobile\Internal\Entity\Marketing\Operator;
use Bitrix\Mobile\Internal\Entity\Marketing\RuleDefinition;
use Bitrix\Mobile\Internal\Integration\Bitrix24\License;
use Bitrix\Mobile\Internal\Service\Marketing\Fact\ActionFacts;
use Bitrix\Mobile\Internal\Service\Marketing\Fact\PermissionFacts;
use Bitrix\Mobile\Internal\Service\Marketing\RuleSet;

/**
 * "tasks.activationBanner" of 703508 in the task list: a cloud portal of the ru region created from the
 * mobile application, its creator, and "Tasks" among the tools of the qualification. The step is the first
 * one not done: create a task, invite a colleague by address, open the screen of the web login; a step the
 * user has no right for is passed over. The deeds count from the creation of the portal.
 *
 * Every unknown stays unknown. The mobile origin and the opened screen have no confirmed source, so on a real
 * portal the rule is unknown and blocks no other rule.
 */
final class TasksActivationBannerRuleSet implements RuleSet
{
	public const CHANNEL = 'banner';
	public const PLACEMENT = 'tasks.list';

	public const RULE = 'tasks.activationBanner';

	public const CHOICE_STEP = 'step';
	public const STEP_CREATE_TASK = 'create_task';
	public const STEP_INVITE = 'invite';
	public const STEP_OPEN_GO_TO_WEB = 'open_go_to_web';

	private const TASKS_TOOL = 'tasks';

	private readonly License $license;

	public function __construct(?License $license = null)
	{
		$this->license = $license ?? new License();
	}

	public function getRules(): array
	{
		return [
			new RuleDefinition(
				self::RULE,
				self::CHANNEL,
				[self::PLACEMENT],
				ConditionGroup::allOf(
					self::is('portal.cloud', true),
					self::is('portal.region', 'ru'),
					self::is('portal.mobileOrigin', true),
					self::is('user.firstAdmin', true),
					self::is('qualification.tools', self::TASKS_TOOL),
				),
				$this->createStepChoice(),
			),
		];
	}

	private function createStepChoice(): Choice
	{
		$since = $this->license->getCreatedAt();
		$threshold = $since === null ? [] : [ActionFacts::PARAM_SINCE => $since];

		return new Choice(self::CHOICE_STEP, [
			self::STEP_CREATE_TASK => ConditionGroup::allOf(
				self::is(ActionFacts::TASK_CREATED, false, $threshold),
				self::is(PermissionFacts::CREATE_TASK, true),
			),
			self::STEP_INVITE => ConditionGroup::allOf(
				self::is(ActionFacts::ADDRESSED_INVITATION_SENT, false, $threshold),
				self::is(PermissionFacts::INVITE, true),
			),
			self::STEP_OPEN_GO_TO_WEB => ConditionGroup::allOf(
				self::is(ActionFacts::GO_TO_WEB_SCREEN_OPENED, false),
			),
		]);
	}

	/**
	 * @param array $factParams parameters of the fact, such as the threshold of a dated action
	 */
	private static function is(string $factCode, mixed $value, array $factParams = []): Condition
	{
		return new Condition($factCode, Operator::InSet, [Condition::PARAM_VALUES => [$value]] + $factParams);
	}
}
