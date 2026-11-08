<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Service;

use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Infrastructure\Rest\Dto\AiSiteGenerationDto;
use Bitrix\Landing\Infrastructure\Rest\Exception\FreeDraftUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\CreateAiSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteStatusDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\GenerationFailedException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\UseCase\CreateAiSiteHandler;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetAiSiteStatusHandler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\V3\DefaultLanguage;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

/**
 * The whole behaviour behind landing.aisite.*: the controller only hands over the request fields
 * and returns what comes back.
 *
 * Rights, product gates and generation ownership belong to the domain and are not re-checked here.
 */
final class AiSiteRestService
{
	private const STATUS_RUNNING = 'running';
	private const STATUS_COMPLETED = 'completed';
	private const STATUS_FAILED = 'failed';

	private const LIMIT_ERROR_TYPE = 'limit';
	private const MODERATION_ERROR_TYPE = 'moderation';

	/** Source language of the phrases: what the answer falls back to when there is no translation. */
	private const FALLBACK_LANGUAGE = 'ru';

	public function __construct(
		private readonly CreateAiSiteHandler $createAiSiteHandler = new CreateAiSiteHandler(),
		private readonly GetAiSiteStatusHandler $getAiSiteStatusHandler = new GetAiSiteStatusHandler(),
		private readonly AiSiteBindingService $bindingService = new AiSiteBindingService(),
		private readonly DomainExceptionMapper $exceptionMapper = new DomainExceptionMapper(),
	)
	{
	}

	/**
	 * Starts a generation for the user the REST request is authenticated as and answers with the
	 * same shape getStatus() answers with, a start the limits already refused included.
	 *
	 * @param string|null $language Language of the answer texts (RestController::getResponseLanguage()).
	 *   Without it the answer speaks the language of the portal.
	 */
	public function generate(string $description, int $userId, ?string $language = null): GetResponse
	{
		$language = $this->resolveLanguage($language);
		$briefLines = CreateAiSiteState::normalizeInputLines([$description]);

		try
		{
			// The draft-independent refusals are asked in the order handle() asks them, so REST and
			// the chat name the same reason and a refused call leaves no draft row behind. The checks
			// inside handle() stay the guarantee.
			$this->createAiSiteHandler->assertAiSitesEnabled();
			$this->createAiSiteHandler->assertUserCanCreateSite($userId);
			$this->createAiSiteHandler->assertAiModuleAvailable();
			$this->createAiSiteHandler->assertSitesAiProductAvailable();

			$bindingId = $this->acquireFreeDraft($userId);

			$result = $this->createAiSiteHandler->handle(
				CreateAiSiteDto::fromArray([
					'briefLines' => $briefLines,
					AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD => $bindingId,
				]),
				$userId,
			);
		}
		catch (GenerationFailedException $exception)
		{
			return new GetResponse($this->createFailedDto($exception->getGenerationId(), $language));
		}
		catch (\Throwable $exception)
		{
			throw $this->exceptionMapper->map($exception) ?? $exception;
		}

		return new GetResponse($this->createGenerationDto($result, $language));
	}

	/**
	 * Reads the state of a generation. Finalizing the binding of a completed generation and freeing
	 * the draft of a moderation-blocked one are side effects of the handler and part of the contract.
	 *
	 * @param string $generationId What the caller asked about, as it came. The strict reading of the
	 *   identifier belongs to the handler: cast here, "123abc" would answer for the generation 123.
	 * @param string|null $language Language of the answer texts, see generate().
	 */
	public function getStatus(string $generationId, int $userId, ?string $language = null): GetResponse
	{
		$language = $this->resolveLanguage($language);
		$dto = GetAiSiteStatusDto::fromArray([
			'jobId' => $generationId,
			'seconds' => 0,
		]);

		try
		{
			$result = $this->getAiSiteStatusHandler->handle($dto, $userId);
		}
		catch (GenerationFailedException $exception)
		{
			return new GetResponse($this->createFailedDto($exception->getGenerationId(), $language));
		}
		catch (\Throwable $exception)
		{
			throw $this->exceptionMapper->map($exception) ?? $exception;
		}

		return new GetResponse($this->createGenerationDto($result, $language));
	}

	/**
	 * Takes the free draft the generation is started on.
	 *
	 * The one step of generate() that fails on competition rather than on the caller: the draft is
	 * one per user behind a named db lock that gives up after a few seconds. Untyped, that would
	 * reach the client as a 500 with a journal record, so it is answered as a transient refusal.
	 * The catch stays on this single call - a RuntimeException from the rest of the chain is an
	 * internal failure and keeps its 500. The refusal is already shaped as the answer, so the mapper
	 * finds no rule for it and generate() rethrows it as it was.
	 */
	private function acquireFreeDraft(int $userId): int
	{
		try
		{
			$bindingId = $this->bindingService->getOrCreateFreeDraft($userId);
		}
		catch (\RuntimeException $exception)
		{
			throw new FreeDraftUnavailableException($exception);
		}

		if ($bindingId <= 0)
		{
			throw new FreeDraftUnavailableException();
		}

		return $bindingId;
	}

	/**
	 * Only the fields of the public contract leave the module - slider codes and the creation
	 * journal the handler also builds are internal. The texts come from the curated set of this
	 * file: what the handler says is addressed to the AI assistant and names the step it fell on.
	 */
	private function createGenerationDto(array $result, string $language): AiSiteGenerationDto
	{
		$status = (string)($result['status'] ?? '');

		$dto = new AiSiteGenerationDto();
		$dto->id = $this->resolveGenerationId($result['jobId'] ?? null);
		$dto->status = $status;
		$dto->siteId = (int)($result['siteId'] ?? 0);
		$dto->pageId = (int)($result['pageId'] ?? 0);
		$dto->draftSiteId = (int)($result['draftSiteId'] ?? 0);
		$dto->draftPageId = (int)($result['draftPageId'] ?? 0);
		$dto->message = $this->resolveMessage($status, $language);
		$dto->error = ($result['error'] ?? null) === null ? null : $this->resolveError($result, $language);
		$dto->errorDetails = $this->resolveErrorDetails($result);

		return $dto;
	}

	/**
	 * A generation that failed without a status answer of its own still has an identifier, so the
	 * client gets the same shape it gets for every other state.
	 */
	private function createFailedDto(int $generationId, string $language): AiSiteGenerationDto
	{
		$dto = new AiSiteGenerationDto();
		$dto->id = $this->resolveGenerationId($generationId);
		$dto->status = self::STATUS_FAILED;
		$dto->siteId = 0;
		$dto->pageId = 0;
		$dto->draftSiteId = 0;
		$dto->draftPageId = 0;
		$dto->message = $this->resolveMessage(self::STATUS_FAILED, $language);
		$dto->error = $this->resolveRaisedFailureError($generationId, $language);
		// a failure raised instead of answered carries no status answer, so there is no limit text to tell
		$dto->errorDetails = null;

		return $dto;
	}

	/**
	 * The reason of a failure the handler raised instead of answering with.
	 *
	 * A topic the moderation refused is the one such failure the client can act on: told the common
	 * reason instead, it repeats the same description until the blocks answer it with 429. The
	 * verdict of the moderation step is written for the user and already localized, so it is what
	 * leaves; the message of the exception names the step and stays inside the module.
	 */
	private function resolveRaisedFailureError(int $generationId, string $language): string
	{
		$moderationReason = AiSiteGenerationHelper::resolveModerationBlockReason($generationId);
		if ($moderationReason === null)
		{
			return $this->getPhrase('LANDING_REST_AI_SITE_ERROR_COMMON', $language);
		}

		return $moderationReason !== ''
			? $moderationReason
			: $this->getPhrase('LANDING_REST_AI_SITE_ERROR_MODERATION', $language)
		;
	}

	/**
	 * The identifier is what status() is then asked about, so an answer without it is an internal
	 * failure of this module rather than a start that succeeded with id = 0.
	 */
	private function resolveGenerationId(mixed $jobId): int
	{
		$generationId = is_scalar($jobId) ? (int)$jobId : 0;
		if ($generationId <= 0)
		{
			throw new \RuntimeException('AI site generation answered without an identifier.');
		}

		return $generationId;
	}

	/** Any state that is neither running nor completed is a failure for the client. */
	private function resolveMessage(string $status, string $language): string
	{
		return $this->getPhrase(
			match ($status)
			{
				self::STATUS_RUNNING => 'LANDING_REST_AI_SITE_MESSAGE_RUNNING',
				self::STATUS_COMPLETED => 'LANDING_REST_AI_SITE_MESSAGE_COMPLETED',
				default => 'LANDING_REST_AI_SITE_MESSAGE_FAILED',
			},
			$language,
		);
	}

	/**
	 * The reason of a failure, told apart by what the answer of the handler carries: a refusal by
	 * the request limits and a generation that stopped after the draft site was already created are
	 * the two cases the client can act on differently.
	 */
	private function resolveError(array $result, string $language): string
	{
		$errorType = (string)($result['errorType'] ?? '');
		if ($errorType === self::LIMIT_ERROR_TYPE)
		{
			return $this->getPhrase('LANDING_REST_AI_SITE_ERROR_LIMIT', $language);
		}

		// A topic the moderation refused is the one failure the client can act on: told the common
		// reason instead, it repeats the same description until the blocks answer it with 429. The
		// verdict of the moderation step is written for the user and already localized, so it is what
		// leaves; the wording of the handler names the step and stays inside the module.
		if ($errorType === self::MODERATION_ERROR_TYPE)
		{
			$reason = AiSiteGenerationHelper::resolveModerationBlockReason(
				$this->resolveGenerationId($result['jobId'] ?? null),
			);

			return ($reason === null || $reason === '')
				? $this->getPhrase('LANDING_REST_AI_SITE_ERROR_MODERATION', $language)
				: $reason
			;
		}

		if (!empty($result['recoverablePartial']))
		{
			return $this->getPhrase('LANDING_REST_AI_SITE_ERROR_PARTIAL_DRAFT', $language);
		}

		return $this->getPhrase('LANDING_REST_AI_SITE_ERROR_COMMON', $language);
	}

	/**
	 * The wording the AI subsystem gave the limit it refused by.
	 *
	 * error says a limit was reached and no more: the phrase of this module cannot tell a quota that
	 * ran out from a rate limit, and the answer of each is a different thing to do. The subsystem
	 * already told them apart in a text written for a person, so that text travels as it is - minus
	 * the interface markup it is written in, see stripInterfaceMarkup().
	 *
	 * The text keeps the language of the moment the generation ran: it was written when the limit
	 * was hit and stored with the generation, so unlike every other text here it does not follow the
	 * language of the request.
	 */
	private function resolveErrorDetails(array $result): ?string
	{
		if ((string)($result['errorType'] ?? '') !== self::LIMIT_ERROR_TYPE)
		{
			return null;
		}

		$details = $this->stripInterfaceMarkup((string)($result['errorText'] ?? ''));

		return $details === '' ? null : $details;
	}

	/**
	 * Turns the text of the limiter into the plain text the contract promises.
	 *
	 * The wording is written for the interface: paragraphs of the message are wrapped in [p], and
	 * the call to action at the end is a [url] link to a slider of the portal that no REST client
	 * can open. The link leaves with its label - told "buy boosts" without a way to, the client has
	 * nothing to do with the words - and the rest of the tags leave as tags, the paragraph break
	 * becoming the space between the sentences. A message the AI subsystem forced upon the portal
	 * carries markup of its own, so the tags are cleared by their shape rather than by a list.
	 */
	private function stripInterfaceMarkup(string $text): string
	{
		$text = (string)preg_replace('/\[url\b[^]]*].*?\[\/url]/isu', ' ', $text);
		$text = (string)preg_replace('/\[\/?[a-z][a-z\d]*(?:=[^]]*)?]/iu', ' ', $text);

		return trim((string)preg_replace('/\s+/u', ' ', $text));
	}

	/**
	 * The asked language is a request header, and Loc reads it as a directory of the lang path, so a
	 * value that is not a language code is answered in the source language instead of being looked up.
	 */
	private function resolveLanguage(?string $language): string
	{
		$language ??= DefaultLanguage::get();

		// \z rather than $: the latter would let a code with a newline after it through
		return preg_match('/\A[a-z]{2}\z/', $language) === 1 ? $language : self::FALLBACK_LANGUAGE;
	}

	/**
	 * Phrases are kept in ru only, so a language without a translation reads the ru text instead of
	 * an empty field - the same fallback RestException takes for the refusals of these methods.
	 */
	private function getPhrase(string $code, string $language): string
	{
		foreach ([$language, self::FALLBACK_LANGUAGE] as $languageCode)
		{
			Loc::loadLanguageFile(__FILE__, $languageCode);

			$message = Loc::getMessage($code, null, $languageCode);
			if ($message !== null)
			{
				return $message;
			}
		}

		return '';
	}
}
