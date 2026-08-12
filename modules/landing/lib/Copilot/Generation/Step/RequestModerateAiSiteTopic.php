<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Dto\ModerationVerdictDto;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Connector\Schema;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Request\RequestSingle;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Metrika;

/**
 * AI-site topic moderation step.
 *
 * Makes a dedicated free LLM request, classifies the topic
 * (allow / soft_block / hard_block) and, when forbidden, aborts generation via
 * GenerationErrors::restrictedRequest (→ ErrorContentPolicy metric).
 *
 * Fail-closed invariant: a topic that could not be checked (failure/invalid response)
 * never reaches generation.
 */
class RequestModerateAiSiteTopic extends RequestSingle
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

	/**
	 * The check is free and is excluded from the preflight quota.
	 */
	public static function getRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		return null;
	}

	public function getAnalyticEvent(): ?Metrika\Events
	{
		return Metrika\Events::dataGeneration;
	}

	protected function getPrompt(): Prompt
	{
		$prompt = new Prompt(PromptCodeCatalog::CREATE_AI_SITE_MODERATION);
		$prompt->setMarkers(['{{input}}' => $this->resolveModerationInput()]);
		$prompt->setSchema(new Schema\AiSiteModerationVerdict());
		// Zero cost regardless of global toggles.
		$prompt->setCost(0);

		return $prompt;
	}

	protected function getRequestLogDataType(): string
	{
		return 'AI request: topic moderation';
	}

	/**
	 * The only scenario-specific seam: the moderated input source.
	 * For creation it is the user brief. Overridden in the change scenario.
	 */
	protected function resolveModerationInput(): string
	{
		return $this->encodeInputLines(CreateAiSiteState::resolveInputLines($this->generation));
	}

	final protected function encodeInputLines(array $inputLines): string
	{
		if ($inputLines === [])
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Moderation input is empty.',
			);
		}

		$raw = json_encode($inputLines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($raw) || $raw === '')
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Moderation input JSON encoding failed.',
			);
		}

		return $raw;
	}

	public function verifyResponse(): void
	{
		if ($this->resolveVerdict() === null)
		{
			// fail-closed: an invalid/missing response must not let the topic through.
			throw new GenerationException(
				GenerationErrors::notCorrectResponse,
				'Moderation verdict response is invalid.',
			);
		}
	}

	protected function applyResponse(): bool
	{
		$verdict = $this->resolveVerdict();
		if ($verdict === null)
		{
			throw new GenerationException(
				GenerationErrors::notCorrectResponse,
				'Moderation verdict response is invalid.',
			);
		}

		if ($verdict->isAllowed())
		{
			return true;
		}

		// soft_block | hard_block — the topic is forbidden, abort generation.
		// The user-facing message is derived from the verdict (RESULT) at display time by the
		// reaction layer: the request is already Received here, so Request::saveError() would be a
		// no-op (it refuses status > Sent). RESULT is the single persisted source of truth.
		if ($verdict->isSoftBlock())
		{
			// Distinct funnel label for a recoverable block; hard blocks stay ErrorContentPolicy.
			throw new GenerationException(
				GenerationErrors::restrictedRequest,
				'',
				['metrikaStatus' => Metrika\Statuses::SoftBlockContentPolicy],
			);
		}

		throw new GenerationException(GenerationErrors::restrictedRequest);
	}

	/**
	 * Localized user-facing refusal, built from the verdict. hard_block — a firm static phrase
	 * (no coaching). soft_block — a static invitation to reword plus the model's user-language hint.
	 * Read from the persisted verdict (RESULT) by the AiAssistant reaction layer.
	 */
	public static function resolveUserFacingMessage(ModerationVerdictDto $verdict): string
	{
		// Loc is resolved inside Error::createError, whose lang file carries these phrases
		// (Loc::getMessage loads the lang file of its calling file, not of this step).
		if ($verdict->isSoftBlock())
		{
			$message = Error::createError(Errors::requestNotAllowedSoft)->message;
			$hint = $verdict->getHint();

			return $hint !== '' ? trim($message . ' ' . $hint) : $message;
		}

		return Error::createError(Errors::requestNotAllowedHard)->message;
	}

	private function resolveVerdict(): ?ModerationVerdictDto
	{
		$result = $this->request?->getResult();

		return is_array($result) ? ModerationVerdictDto::fromArray($result) : null;
	}
}
