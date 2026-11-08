<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\Bizproc\EventHandler;

use Bitrix\BIConnector\Integration\UI\EntitySelector\DigestPeriodProvider;
use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\EntitySelector\SelectorConfiguration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Value selectors of the digest scope for the setup wizard of a system agent template. A constant of the
 * wizard offers its type only once the selector it names is registered, so this handler is what makes the
 * two scope constants settable at all.
 *
 * An unavailable scenario is refused rather than answered with an empty list: the provider of the wizard
 * drops an ERROR result whole, while an empty SUCCESS would register no selector and leave the constants
 * of every other system agent to explain a type nothing can fill.
 */
final class SetupTemplateActivitySelectorProvider
{
	/** Read by the wizard form; fixed by the cross-feature contract of the digest and never renamed. */
	public const SELECTOR_ID_WORKFLOW_TEMPLATE = 'biconnector-digest-scope-workflow-template';
	public const SELECTOR_ID_TASKS_FLOW = 'biconnector-digest-scope-tasks-flow';
	public const SELECTOR_ID_PERIOD = 'biconnector-digest-period';

	private const ENTITY_ID_WORKFLOW_TEMPLATE = 'bizproc-template';
	private const ENTITY_ID_TASKS_FLOW = 'flow';
	private const ENTITY_ID_PERIOD = DigestPeriodProvider::ENTITY_ID;

	public static function onProvideSelectors(Event $event): EventResult
	{
		if (!Loader::includeModule('bizproc') || !self::isDigestScenarioEnabled())
		{
			return new EventResult(EventResult::ERROR);
		}

		return new EventResult(EventResult::SUCCESS, [
			'selectors' => [
				new SelectorConfiguration(
					id: self::SELECTOR_ID_WORKFLOW_TEMPLATE,
					title: (string)Loc::getMessage('BI_DIGEST_SCOPE_SELECTOR_WORKFLOW_TEMPLATE'),
					dialogOptions: self::makeDialogOptions(self::ENTITY_ID_WORKFLOW_TEMPLATE),
				),
				new SelectorConfiguration(
					id: self::SELECTOR_ID_TASKS_FLOW,
					title: (string)Loc::getMessage('BI_DIGEST_SCOPE_SELECTOR_TASKS_FLOW'),
					dialogOptions: self::makeDialogOptions(self::ENTITY_ID_TASKS_FLOW),
				),
				new SelectorConfiguration(
					id: self::SELECTOR_ID_PERIOD,
					title: (string)Loc::getMessage('BI_DIGEST_PERIOD_SELECTOR'),
					dialogOptions: self::makePeriodDialogOptions(),
				),
			],
		]);
	}

	/**
	 * The same option gates both digest activities: without it the BI selectors would show up in the
	 * wizard of every system agent, including the ones the digest has nothing to do with.
	 */
	private static function isDigestScenarioEnabled(): bool
	{
		return Option::get('bizproc', 'bitrix_ai_bi_dashboard_available', 'N') === 'Y';
	}

	/**
	 * Both providers fill the dialog on demand, so neither value list is preloaded into the form.
	 */
	/**
	 * The periods are filled in by their own provider, which knows the whole list: the dialog only needs
	 * to be pointed at the entity.
	 */
	private static function makePeriodDialogOptions(): array
	{
		return [
			'entities' => [
				[
					'id' => self::ENTITY_ID_PERIOD,
					'dynamicLoad' => true,
					'dynamicSearch' => true,
				],
			],
			'showAvatars' => false,
			'height' => 320,
		];
	}

	private static function makeDialogOptions(string $entityId): array
	{
		return [
			'entities' => [
				[
					'id' => $entityId,
					'dynamicLoad' => true,
					'dynamicSearch' => true,
				],
			],
		];
	}
}
