<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\GenerationAbortedException;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Step;
use Bitrix\Landing\Markup\MarkupCreationOutcome;

/**
 * Product scenario: a site is built from a markup package accepted by the intake service.
 *
 * Only source-preserving steps are in the map: the markup/Tailwind steps of CreateAiSite
 * would destroy custom code, and no step asks the model for the content of the page - the
 * external calls are the repair node fixing gate codes on the submitted source (ADR R10) and
 * the generation of the pictures the package declares.
 * There is no publication step - the user publishes with the editor button (nothing goes
 * live without consent).
 *
 * The class name goes into the SCENARIO column and is listed in
 * CreateAiSiteChecker::getAiSiteScenarios(), so sites of this scenario open in the AI-site
 * editor (combo mode). ChangeMarkupSite subclasses this scenario with its own map:
 * instanceof-consumers of the markup family capture both automatically.
 */
class CreateMarkupSite extends CreateAiSite
{
	private const STEP_INIT_CONTEXT = 10;
	private const STEP_ACCEPT_PACKAGE = 20;
	private const STEP_REPAIR_PACKAGE = 30;
	private const STEP_MODERATE_TOPIC = 40;
	private const STEP_GENERATE_IMAGES = 50;
	private const STEP_PROVISION_FORMS = 60;
	private const STEP_CREATE_SITE = 70;
	private const STEP_CREATE_BLOCK = 80;
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
			self::STEP_CREATE_SITE => new Step\TaskCreateSite(),
			self::STEP_CREATE_BLOCK => new Step\TaskCreateMarkupBlock(),
			self::STEP_FINISH => new Step\TaskFinishCreateMarkupSite(),
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
			self::STEP_CREATE_SITE => [
				'code' => 'create_site',
				'label' => 'Create site and landing',
			],
			self::STEP_CREATE_BLOCK => [
				'code' => 'create_block',
				'label' => 'Add markup block to landing',
			],
			self::STEP_FINISH => [
				'code' => 'finish',
				'label' => 'Finish',
			],
		];
	}

	/**
	 * Topic moderation is the only step that reaches the model, so the preflight check
	 * stands right before it; the repair node lives outside of the request quotas.
	 */
	public function getQuotaCalculateStep(): ?int
	{
		return self::STEP_MODERATE_TOPIC;
	}

	/**
	 * The step whose refusal means a forbidden topic, not a technical failure.
	 */
	public function getModerationStepId(): ?int
	{
		return self::STEP_MODERATE_TOPIC;
	}

	/**
	 * A cancel that a run has found on its own step (Generation::cancel() of another process, while the
	 * run held the generation) ends the run with the abort: the run records the same last error the
	 * cancel handler records, so the status of the job reads the cancel whichever of the two wrote last.
	 */
	public function onGenerationError(Generation $generation, GenerationException $e): void
	{
		parent::onGenerationError($generation, $e);

		if ($e instanceof GenerationAbortedException)
		{
			(new MarkupSiteState($generation))->setLastError(MarkupSiteState::CANCELLED_MESSAGE);
		}
	}

	/**
	 * The scenario owns the chat binding of the creation (ADR § 3.3): a terminal failure frees the chat
	 * and removes the partial site. The outcome itself reacts only on this exact scenario.
	 */
	public function onTerminalFailure(Generation $generation, \Throwable $e): void
	{
		$this->createCreationOutcome()->reactOnTerminal($generation);
	}

	protected function createCreationOutcome(): MarkupCreationOutcome
	{
		return new MarkupCreationOutcome();
	}

	/**
	 * No parent-child async relations: the only step that waits for an external
	 * answer (the repair node) blocks in place instead of running in background.
	 */
	public function getAsyncRelations(): ?array
	{
		return null;
	}
}
