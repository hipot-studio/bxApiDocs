<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Step;

/**
 * Product scenario: a new markup package version is applied to an existing page.
 *
 * The edit path goes through Generation as well (ADR invariant: nothing changes a
 * markup site outside of a scenario), but the create-map steps do not fit - the site
 * and the block already exist. Subclassing CreateMarkupSite keeps every instanceof
 * consumer of the markup family (status tool, event routing) working; the AI-site
 * origin of the target is defined by its create generation, so this class name is
 * deliberately absent from CreateAiSiteChecker::getAiSiteScenarios().
 */
class ChangeMarkupSite extends CreateMarkupSite
{
	public const EVENT_FINISH = 'onChangeMarkupSiteFinish';
	private const SCENARIO_CODE = 'change_markup_site';
	private const STEP_INIT_CONTEXT = 10;
	private const STEP_ACCEPT_PACKAGE = 20;
	private const STEP_REPAIR_PACKAGE = 30;
	private const STEP_MODERATE_TOPIC = 40;
	private const STEP_GENERATE_IMAGES = 50;
	private const STEP_PROVISION_FORMS = 60;
	private const STEP_APPLY_PACKAGE = 70;
	private const STEP_FINISH = 1000;

	protected function buildMap(): array
	{
		return [
			self::STEP_INIT_CONTEXT => new Step\TaskInitMarkupContext(),
			self::STEP_ACCEPT_PACKAGE => new Step\TaskAcceptMarkupPackage(),
			self::STEP_REPAIR_PACKAGE => new Step\TaskRepairMarkupPackage(),
			self::STEP_MODERATE_TOPIC => new Step\RequestModerateMarkupSiteTopic(),
			self::STEP_GENERATE_IMAGES => new Step\RequestMarkupPackageImages(),
			self::STEP_PROVISION_FORMS => new Step\TaskProvisionMarkupForms(),
			self::STEP_APPLY_PACKAGE => new Step\TaskApplyMarkupPackage(),
			self::STEP_FINISH => new Step\TaskFinishChangeMarkupSite(),
		];
	}

	public function getStepMeta(): array
	{
		return [
			self::STEP_INIT_CONTEXT => [
				'code' => 'init',
				'label' => 'Prepare context',
			],
			self::STEP_ACCEPT_PACKAGE => [
				'code' => 'accept_package',
				'label' => 'Validate markup package',
			],
			self::STEP_REPAIR_PACKAGE => [
				'code' => 'repair_package',
				'label' => 'Repair markup package',
			],
			self::STEP_MODERATE_TOPIC => [
				'code' => 'moderate_topic',
				'label' => 'Moderate page topic',
			],
			self::STEP_GENERATE_IMAGES => [
				'code' => 'generate_images',
				'label' => 'Generate package images',
			],
			self::STEP_PROVISION_FORMS => [
				'code' => 'provision_forms',
				'label' => 'Create CRM forms',
			],
			self::STEP_APPLY_PACKAGE => [
				'code' => 'apply_package',
				'label' => 'Apply the new package version',
			],
			self::STEP_FINISH => [
				'code' => 'finish',
				'label' => 'Finish',
			],
		];
	}

	public function getQuotaCalculateStep(): ?int
	{
		return self::STEP_MODERATE_TOPIC;
	}

	public function getModerationStepId(): ?int
	{
		return self::STEP_MODERATE_TOPIC;
	}

	/**
	 * Tells the editor of the page that the new version is applied, so the open editor reloads its frame.
	 * A repeated run of an already finished generation reports nothing: the editor has reloaded already.
	 */
	public function onFinish(Generation $generation): void
	{
		if ($generation->isFinishedPersisted())
		{
			return;
		}

		$landingId = (int)($generation->getSiteData()->getLandingId() ?? 0);
		if ($landingId <= 0)
		{
			return;
		}

		$params = [
			'scenario' => self::SCENARIO_CODE,
			'eventType' => 'finish',
			'stepCode' => 'finish',
		];
		$blockId = (new MarkupSiteState($generation))->getBlockId();
		if ($blockId > 0)
		{
			$params['blockId'] = $blockId;
		}

		try
		{
			$generation->getEvent()->send(self::EVENT_FINISH, $params);
		}
		catch (\Throwable)
		{
			// the version is applied already: a lost notice of the editor must not turn the edit into an error
		}
	}

	/**
	 * An edit reserves no chat and must never lose its page: a failure of it has nothing to react on.
	 */
	public function onTerminalFailure(Generation $generation, \Throwable $e): void
	{
	}
}
