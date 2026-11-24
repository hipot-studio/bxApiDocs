<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing\Rule;

use Bitrix\Mobile\Internal\Entity\Marketing\Choice;
use Bitrix\Mobile\Internal\Entity\Marketing\Condition;
use Bitrix\Mobile\Internal\Entity\Marketing\ConditionGroup;
use Bitrix\Mobile\Internal\Entity\Marketing\Operator;
use Bitrix\Mobile\Internal\Entity\Marketing\RuleDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\Verdict;
use Bitrix\Mobile\Internal\Service\Marketing\Fact\PortalFacts;
use Bitrix\Mobile\Internal\Service\Marketing\RuleSet;

/**
 * The support banners of the "More" menu:
 * - "menu.supportBanners" - who sees them: a cloud portal of the ru region, at most 60 full days old on
 *   the clock of the server as master counted, and a current administrator; a portal of unknown age
 *   counts as young;
 * - "menu.supportBanners.form" - which form they open, whoever asks: the smaller company form below 100
 *   people and while the size is unknown, the larger one from 100 on.
 */
final class SupportBannersRuleSet implements RuleSet
{
	public const CHANNEL = 'banner';
	public const PLACEMENT = 'menu.more';

	public const AUDIENCE = 'menu.supportBanners';
	public const FORM = 'menu.supportBanners.form';

	public const CHOICE_FORM = 'form';
	public const FORM_LT_100 = 'lt100';
	public const FORM_GT_100 = 'gt100';

	private const MAX_PORTAL_AGE_DAYS = 60;
	private const SMALL_COMPANY_MAX_SIZE = 99;

	public function getRules(): array
	{
		return [
			self::rule(self::AUDIENCE, ConditionGroup::allOf(
				self::is('portal.cloud', true),
				self::is('portal.region', 'ru'),
				new Condition(
					'portal.ageDays',
					Operator::Range,
					[
						Condition::PARAM_FROM => 0,
						Condition::PARAM_TO => self::MAX_PORTAL_AGE_DAYS,
						PortalFacts::PARAM_DAYS => PortalFacts::DAYS_FULL,
					],
					Verdict::Matched,
				),
				self::is('user.currentAdmin', true),
			)),
			self::rule(self::FORM, ConditionGroup::allOf(), new Choice(self::CHOICE_FORM, [
				self::FORM_LT_100 => ConditionGroup::allOf(new Condition(
					'qualification.companySize',
					Operator::Range,
					[Condition::PARAM_FROM => 0, Condition::PARAM_TO => self::SMALL_COMPANY_MAX_SIZE],
					Verdict::Matched,
				)),
				self::FORM_GT_100 => ConditionGroup::allOf(),
			])),
		];
	}

	private static function is(string $factCode, mixed $value): Condition
	{
		return new Condition($factCode, Operator::InSet, [Condition::PARAM_VALUES => [$value]]);
	}

	private static function rule(string $code, ConditionGroup $conditions, ?Choice $choice = null): RuleDefinition
	{
		return new RuleDefinition($code, self::CHANNEL, [self::PLACEMENT], $conditions, $choice);
	}
}
