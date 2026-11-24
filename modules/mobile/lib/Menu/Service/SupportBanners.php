<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Service;

use Bitrix\Mobile\Internal\Entity\Marketing\RuleEvaluation;
use Bitrix\Mobile\Internal\Entity\Marketing\SelectionResult;
use Bitrix\Mobile\Internal\Entity\Marketing\Verdict;
use Bitrix\Mobile\Public\Provider\Marketing\SelectionProvider;

/**
 * The support banners of the "More" menu, as the shared selection decides them for the signed-in user:
 * whether he sees them and which form they open. Nobody signed in sees nothing and gets the smaller form.
 */
final class SupportBanners
{
	private const CHANNEL = 'banner';
	private const PLACEMENT = 'menu.more';
	private const AUDIENCE_RULE = 'menu.supportBanners';
	private const FORM_RULE = 'menu.supportBanners.form';
	private const FORM_CHOICE = 'form';

	private const FORM_ID_LT_100 = 'integrationConsultLt100';
	private const FORM_ID_GT_100 = 'integrationConsultGt100';

	/** The form chosen by the rule => the form the menu opens. */
	private const FORM_IDS = [
		'lt100' => self::FORM_ID_LT_100,
		'gt100' => self::FORM_ID_GT_100,
	];

	private readonly SelectionProvider $provider;

	/**
	 * Both rules for the signed-in user once selected: rule code => evaluation, empty when nobody is signed
	 * in or the selection failed.
	 *
	 * @var array<string, RuleEvaluation>|null
	 */
	private ?array $evaluations = null;

	public function __construct(?SelectionProvider $provider = null)
	{
		$this->provider = $provider ?? new SelectionProvider();
	}

	public function shouldShowSupportBanners(): bool
	{
		return $this->evaluate(self::AUDIENCE_RULE)?->verdict === Verdict::Matched;
	}

	public function getFormCode(): string
	{
		$form = $this->evaluate(self::FORM_RULE)?->chosen[self::FORM_CHOICE] ?? null;

		return self::FORM_IDS[$form] ?? self::FORM_ID_LT_100;
	}

	/**
	 * The rule for the signed-in user, null when nobody is signed in or the selection failed. Both rules are
	 * selected together on the first question, so a menu asking both costs one evaluation.
	 */
	private function evaluate(string $ruleCode): ?RuleEvaluation
	{
		$this->evaluations ??= $this->selectBoth();

		return $this->evaluations[$ruleCode] ?? null;
	}

	/**
	 * @return array<string, RuleEvaluation>
	 */
	private function selectBoth(): array
	{
		$result = $this->provider->selectForCurrentUser(
			self::CHANNEL,
			self::PLACEMENT,
			[self::AUDIENCE_RULE, self::FORM_RULE],
		);
		$selection = $result->isSuccess() ? ($result->getData()[SelectionProvider::DATA_SELECTION] ?? null) : null;
		if (!$selection instanceof SelectionResult)
		{
			return [];
		}

		return $selection->getEvaluations($selection->getUserIds()[0] ?? 0);
	}
}
