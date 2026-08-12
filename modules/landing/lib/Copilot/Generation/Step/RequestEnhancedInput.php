<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\RequestSingle;
use Bitrix\Landing\AI\SiteBuilder\Dto\EnhancedInputDto;
use Bitrix\Landing\AI\SiteBuilder\ImageStyle\ImageStyleBriefResolver;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Connector\Schema;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Metrika;

class RequestEnhancedInput extends RequestSingle
{
	public function __construct()
	{
		parent::__construct();
		$this->initializeConnector();
	}

	public static function getConnectorClass(): string
	{
		return AI\CreateAiSiteText::class;
	}

	public static function getRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		return new RequestQuotaDto(self::getConnectorClass(), 1);
	}

	public function getAnalyticEvent(): ?Metrika\Events
	{
		return Metrika\Events::dataGeneration;
	}

	protected function getPrompt(): Prompt
	{
		$markers = CreateAiSiteState::buildPromptMarkers([
			'{{input}}' => $this->buildRawInputJson(),
		]);
		$prompt = new Prompt(PromptCodeCatalog::CREATE_AI_SITE_INPUT_ENHANCE);
		$prompt->setMarkers($markers);
		$prompt->setSchema(new Schema\CreateAiSiteEnhancedInput());

		return $prompt;
	}

	protected function getRequestLogDataType(): string
	{
		return 'AI request: input enhancement';
	}

	private function buildRawInputJson(): string
	{
		$inputLines = CreateAiSiteState::resolveInputLines($this->generation);
		if ($inputLines === [])
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'CreateAiSite input is empty.',
			);
		}

		$rawInput = json_encode($inputLines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($rawInput) || $rawInput === '')
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'CreateAiSite input JSON encoding failed.',
			);
		}

		return $rawInput;
	}

	protected function applyResponse(): bool
	{
		$dto = $this->getResultDto();
		if ($dto === null)
		{
			return false;
		}

		$enhancedInput = $dto->toArray();
		$enhancedInput['imageStyleBrief'] = ImageStyleBriefResolver::resolve($enhancedInput);

		if (Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addResponse(
				(int)$this->stepId,
				'AI response: input enhancement',
				$this->request->getResult(),
			);
		}

		CreateAiSiteState::setEnhancedInput($this->generation, $enhancedInput);

		return true;
	}

	public function verifyResponse(): void
	{
		$result = $this->request->getResult();
		if (!is_array($result))
		{
			throw new GenerationException(GenerationErrors::notExistResponse);
		}

		if ($this->getResultDto() === null)
		{
			throw new GenerationException(
				GenerationErrors::notCorrectResponse,
				'Enhanced input response is invalid.',
			);
		}
	}

	private function getResultDto(): ?EnhancedInputDto
	{
		$result = $this->request->getResult();

		return is_array($result) ? EnhancedInputDto::fromArray($result) : null;
	}
}
