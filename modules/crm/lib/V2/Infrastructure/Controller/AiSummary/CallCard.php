<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Controller\AiSummary;

use Bitrix\Crm\Integration\AI\Dto\ClientSummaryData;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Integration\Voximplant\CallClientResolver;
use Bitrix\Crm\V2\Internal\Service\AiSummary\CallCardSummaryLaunchService;
use Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryState;
use Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryStateReader;
use Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryVisibility;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Request;

/**
 * API-01 and API-02: the tab of the call card asks about the summary of the client of that call and
 * asks for it to be made.
 *
 * Both actions are given the identifier of a call and nothing else - the client is never named by the
 * caller, it is worked out of the call here, so a guess at somebody else's identifiers buys nothing.
 * Both go through one and the same order of checks, and the order is the contract: the refusals are
 * told apart by their codes, and every one of them means to the frontend that there is no tab.
 *
 * "There is no such call" and "the call is not yours" are answered by one code on purpose. Told apart,
 * they would answer a walk over the identifiers of calls with the very thing the check of participation
 * is written against - whom a foreign call was with.
 *
 * No state is parsed here: the controller only ties the resolving of the call, the rights, the reader
 * of the state ({@see ClientSummaryStateReader}) and the launch ({@see CallCardSummaryLaunchService})
 * together.
 */
final class CallCard extends Controller
{
	private const ERROR_INVALID_REQUEST = 'invalid_request';
	private const ERROR_SUMMARY_UNAVAILABLE = 'summary_unavailable';
	private const ERROR_CALL_NOT_FOUND = 'call_not_found';
	private const ERROR_NO_CLIENT = 'no_client';
	private const ERROR_ACCESS_DENIED = 'access_denied';

	private CallClientResolver $callResolver;
	private ClientSummaryVisibility $visibility;
	private ClientSummaryStateReader $stateReader;
	private CallCardSummaryLaunchService $launchService;

	/**
	 * The request comes first because that is the only argument the engine builds a controller with.
	 *
	 * @see \Bitrix\Main\Engine\ControllerBuilder::build
	 */
	public function __construct(
		?Request $request = null,
		?CallClientResolver $callResolver = null,
		?ClientSummaryVisibility $visibility = null,
		?ClientSummaryStateReader $stateReader = null,
		?CallCardSummaryLaunchService $launchService = null,
	)
	{
		parent::__construct($request);

		$this->callResolver = $callResolver ?? new CallClientResolver();
		$this->visibility = $visibility ?? new ClientSummaryVisibility();
		$this->stateReader = $stateReader ?? new ClientSummaryStateReader();
		$this->launchService = $launchService ?? new CallCardSummaryLaunchService();
	}

	/**
	 * The tab lives inside the window of a call of the portal, so the actions answer an asynchronous
	 * request of that window and are not published to REST.
	 */
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new Scope(Scope::NOT_REST),
		];
	}

	/**
	 * @return array|null the state of the tab (DTO-01), or null with one error of API-01
	 */
	#[Rule\Scope(Scope::AJAX)]
	#[Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function getStateAction(string $callId): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		$client = $this->resolveReadableClient($callId, $userId);
		if ($client === null)
		{
			return null;
		}

		['state' => $state, 'summary' => $summary] = $this->stateReader->read($client, $userId);

		return $this->buildResponse($client, $state, $summary);
	}

	/**
	 * The queueing is not waited for: collecting the data of the client and calling the engine is not
	 * put on the time of the answer, so there is one answer and it is `running` (API-02). Everything
	 * that happens to the job afterwards reaches the tab as a signal.
	 *
	 * A second job is not created here either - the deduplication and the lock stay with the launcher
	 * behind the service (AC-014).
	 *
	 * @return array|null the state of the tab (DTO-01), or null with one error of API-02
	 */
	#[Rule\Scope(Scope::AJAX)]
	#[Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function launchAction(string $callId): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		$client = $this->resolveReadableClient($callId, $userId);
		if ($client === null)
		{
			return null;
		}

		$this->launchService->launch($userId, $client);

		return $this->buildResponse($client, ClientSummaryState::Running, null);
	}

	/**
	 * The five checks of [P1.T5] in their order, shared by both actions: an identifier of a call, the
	 * measure of the feature, the call itself together with the participation of this user, a client to
	 * write a summary of, and the right to read that client.
	 *
	 * The right is asked on every request and not only when a job is queued: the text of the summary
	 * reaches the person by the answer of API-01 alone, and between the queueing and the reading the
	 * right may be gone (AC-015). This is the one place where it is asked at all - the reader of the
	 * state asks about no rights.
	 */
	private function resolveReadableClient(string $callId, int $userId): ?ItemIdentifier
	{
		if (trim($callId) === '')
		{
			$this->addError(new Error('The call is not named.', self::ERROR_INVALID_REQUEST));

			return null;
		}

		if (!$this->visibility->isAvailableFor($userId))
		{
			$this->addError(new Error('The client summary is not available.', self::ERROR_SUMMARY_UNAVAILABLE));

			return null;
		}

		$call = $this->callResolver->resolve($callId);
		if ($call === null || !$call->hasParticipant($userId))
		{
			$this->addError(new Error('The call is unknown.', self::ERROR_CALL_NOT_FOUND));

			return null;
		}

		$client = $call->getClient();
		if ($client === null)
		{
			$this->addError(new Error('The call has no client to write a summary of.', self::ERROR_NO_CLIENT));

			return null;
		}

		if (!$this->visibility->isElementReadable($userId, $client->getEntityTypeId(), $client->getEntityId()))
		{
			$this->addError(new Error('Access denied.', self::ERROR_ACCESS_DENIED));

			return null;
		}

		return $client;
	}

	/**
	 * DTO-01. The note of the completeness travels under the name of the contract of the wire, while in
	 * the answer of the model it is `coverage_note`: the two names differ on purpose, and the renaming
	 * happens here and only here.
	 *
	 * @return array{status: string, clientTypeId: int, clientId: int, summary: array|null}
	 */
	private function buildResponse(
		ItemIdentifier $client,
		ClientSummaryState $state,
		?ClientSummaryData $summary,
	): array
	{
		return [
			'status' => $state->value,
			'clientTypeId' => $client->getEntityTypeId(),
			'clientId' => $client->getEntityId(),
			'summary' => $summary === null
				? null
				: [
					'blocks' => $summary->blocks,
					'coverageNote' => $summary->coverage_note,
				]
			,
		];
	}

	/**
	 * The identifier of the call arrives from the client as a string and the binder refuses a request
	 * that carries none. That is a malformed request of API-01, so it is told by the code of the
	 * contract and never by the message of the engine.
	 */
	protected function runProcessingBinderThrowable(BinderArgumentException $e): void
	{
		$this->addError(new Error('The request of the summary is malformed.', self::ERROR_INVALID_REQUEST));
	}
}
