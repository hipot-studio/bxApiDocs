<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\MarkupTaskStep;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Markup;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Landing\Rights;

/**
 * Creates the single custom block of the page and applies the package as its first
 * version through the intake service, with the outcomes of the image step: the generated
 * pictures become the files of their tokens, the summary of the images goes to the state for
 * the answers of the tools. Idempotent: a retry with a stored block id only re-applies the
 * version, a second block is never created.
 * A refusal of the file store keeps its code, as the apply step does.
 */
class TaskCreateMarkupBlock extends MarkupTaskStep
{
	protected function doExecute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		$landingId = (int)($this->siteData->getLandingId() ?? 0);
		if ($landingId <= 0)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Landing is missing for markup block creation.',
			);
		}

		$state = new MarkupSiteState($this->generation);
		$package = $state->getPackage();
		if (!$package)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'No markup package was submitted.',
			);
		}

		$rightsBefore = Rights::isOn();
		$editModeBefore = Landing::getEditMode();
		Rights::setGlobalOff();
		Landing::setEditMode(true);
		try
		{
			$landing = Landing::createInstance($landingId, ['check_permissions' => false]);
			if (!$landing->exist())
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					'Landing not found for markup block creation.',
				);
			}

			$intake = $this->createIntake();

			$blockId = $state->getBlockId();
			$block = $blockId > 0 ? new Block($blockId) : null;
			if (!$block || !$block->exist())
			{
				$block = Markup\Intake::findCustomBlock($landing) ?? $intake->createBlock($landing);
			}
			if (!$block)
			{
				$reasons = array_map(
					static fn(\Bitrix\Main\Error $error): string => $error->getMessage(),
					$landing->getError()->getErrors(),
				);

				throw new GenerationException(
					GenerationErrors::dataValidation,
					'Failed to add the markup block to the landing: ' . implode('; ', $reasons),
				);
			}

			$state->setBlockId($block->getId());
			$this->changed = true;
			$this->generation->persistState();

			$result = $intake->apply($package, $block, $landingId, $state->getImageOutcomes());
			TaskApplyMarkupPackage::rememberBuildRefusal($this->generation, $result);
			if (!$result->isSuccess())
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					implode('; ', $result->getErrorMessages()),
				);
			}
			$state->setImageSummary((array)($result->getData()['images'] ?? []));

			$landing->touch();
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
			else
			{
				Rights::setGlobalOff();
			}
		}

		return true;
	}

	protected function createIntake(): Markup\Intake
	{
		return new Markup\Intake();
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}
}
