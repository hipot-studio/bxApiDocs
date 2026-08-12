<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteStyleContextExtractor;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Landing;

class TaskCollectChangeAiSiteStyleContext extends TaskStep
{
	public function execute(): bool
	{
		parent::execute();

		$landingId = $this->resolveRequiredLandingId();
		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode(true);
		try
		{
			$landing = $this->createLanding($landingId);
			if (!$landing->exist())
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					'Landing does not exist for ChangeAiSite style context collection.',
				);
			}

			ChangeAiSiteState::setStyleContext(
				$this->generation,
				(new ChangeAiSiteStyleContextExtractor())->extract($this->collectBlockHtml($landing)),
			);
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
		}

		$this->changed = true;

		return true;
	}

	private function resolveRequiredLandingId(): int
	{
		$landingId = ChangeAiSiteState::resolveLandingId($this->generation);
		if ($landingId > 0)
		{
			return $landingId;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'ChangeAiSite landingId is empty.',
		);
	}

	protected function createLanding(int $landingId): Landing
	{
		return Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
	}

	protected function collectBlockHtml(Landing $landing): array
	{
		$htmlBlocks = [];
		foreach ($landing->getBlocks() as $block)
		{
			$htmlBlocks[] = (string)$block->getContent();
		}

		return $htmlBlocks;
	}
}
