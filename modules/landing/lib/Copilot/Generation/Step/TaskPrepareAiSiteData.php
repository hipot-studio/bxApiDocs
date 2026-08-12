<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\AI\SiteBuilder\Dto\EnhancedInputDto;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;

class TaskPrepareAiSiteData extends TaskStep
{
	public function execute(): bool
	{
		parent::execute();

		$enhanced = EnhancedInputDto::fromArray(CreateAiSiteState::getEnhancedInput($this->generation));
		if ($enhanced === null)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Enhanced input is missing for CreateAiSite.',
			);
		}

		$rawInput = CreateAiSiteState::resolveRawInput($this->generation);
		$title = $enhanced->buildTitleSeed($rawInput);
		$colors = $this->siteData->getColors();
		$colors->theme = $enhanced->getThemeColor() ?? '';

		$this->siteData
			->setSiteTitle($title)
			->setPageTitle($title)
			->setPageMetaTitle($enhanced->getSeoTitle())
			->setPageDescription($enhanced->getSeoDescription())
			->setKeywords($enhanced->getSeoKeywords())
			->setPreviewImagePromptText($enhanced->getPreviewPrompt())
		;

		return true;
	}
}
