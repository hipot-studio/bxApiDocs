<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Controller;

use Bitrix\Landing\Infrastructure\Rest\Dto\AiSiteGenerationDto;
use Bitrix\Landing\Infrastructure\Rest\Request\GenerateAiSiteRequest;
use Bitrix\Landing\Infrastructure\Rest\Service\AiSiteRestService;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Interaction\Request\IdRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

#[DtoType(AiSiteGenerationDto::class)]
#[Title('AI site generation')]
#[Description(
	'Creates a site out of a text description with AI and tells how a started generation is going. '
	. 'A generation belongs to the user the request is authenticated as, and only that user reads it.'
)]
class AiSite extends RestController
{
	/** Source language of the phrases: what an answer with an unusable language code falls back to. */
	private const FALLBACK_LANGUAGE = 'ru';

	/**
	 * The dispatcher builds the controller with the request alone (ControllerBuilder::build()), so
	 * the service is injectable and defaults to the production one.
	 */
	public function __construct(
		?Request $request = null,
		private readonly AiSiteRestService $service = new AiSiteRestService(),
	)
	{
		parent::__construct($request);
	}

	#[Idempotent]
	#[Title('Start an AI site generation')]
	#[Description(
		'Starts the generation of a site from the description and answers with the state of the '
		. 'started generation - the same state landing.aisite.status is then asked about, so a start '
		. 'the limits already refused is read from this answer instead of from a second call. The '
		. 'description is required and holds at most 4000 characters. '
		. 'Sending the same request twice is only protected against when the client sends an '
		. '`Idempotency-Key` header, and only once the first call has answered: the answer reaches '
		. 'the store when the call ends, and a repeat with the same key then gives it back instead '
		. 'of starting a second generation - the key is remembered for 24 hours. A repeat sent '
		. 'while the first call is still running is not covered by the key: while the site draft is '
		. 'still free it is refused as a competing call, and once the first call has taken the '
		. 'draft it starts a second generation of its own. A client that gives up on the answer by '
		. 'a timeout and repeats the start therefore risks a second generation. Without the header '
		. 'every call starts a new generation. '
		. 'The generation takes the one free site draft the user has, the same draft the AI site chat '
		. 'of the portal works on: a chat the user has open at that moment loses its draft and has to '
		. 'be reloaded, so do not start a generation for a user who is working in the chat.'
	)]
	public function generateAction(GenerateAiSiteRequest $request): GetResponse
	{
		return $this->service->generate(
			$request->description,
			$this->getCurrentUserId(),
			$this->getResponseLanguage(),
		);
	}

	#[Title('State of an AI site generation')]
	#[Description(
		'Reads the state of a generation: `running` while it is going, `completed` with the '
		. 'identifiers of the created site and page, or `failed` with the reason. A generation of '
		. 'another user is answered as a missing one, so that its existence is not disclosed.'
	)]
	public function statusAction(IdRequest $request): GetResponse
	{
		return $this->service->getStatus(
			$request->id,
			$this->getCurrentUserId(),
			$this->getResponseLanguage(),
		);
	}

	/**
	 * The asked language comes from a request header, and Loc reads it as a directory of the lang
	 * path, so a value that is not a language code is answered in the source language instead of
	 * being looked up.
	 *
	 * Normalizing it in the service is not enough: the framework asks the controller for the
	 * language again when it turns an exception into an answer (RestController::buildErrorFromException()),
	 * so the refusals of these methods would still be looked up by the header as it came.
	 */
	public function getResponseLanguage(): string
	{
		$language = parent::getResponseLanguage();

		// \z rather than $: the latter would let a code with a newline after it through
		return preg_match('/\A[a-z]{2}\z/', $language) === 1 ? $language : self::FALLBACK_LANGUAGE;
	}

	private function getCurrentUserId(): int
	{
		return (int)$this->getCurrentUser()->getId();
	}
}
