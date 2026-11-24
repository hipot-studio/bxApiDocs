<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\Agent;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\AbstractAgentRestController;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter\AgentTokenFilter;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter\EntryAdmissionFilter;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\AgentSessionDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\EntryOperationFailedException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\FeatureUnavailableException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Response\SessionResponse;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\AgentEntryVerdict;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentEntryAuthorizationService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentWebhookService;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

/**
 * The way into the contour for a caller who holds an ordinary application key rather than a token of it.
 *
 * bizprocdesigner.agent.session.get - may a session be opened over this template, and is one open.
 * bizprocdesigner.agent.session.add - open one, and answer with the base URL to address the contour with.
 *
 * Past this door the actions of the contour take either the token this door issues - still bound to one
 * template and still expiring within the day - or the application key itself, which names the template on
 * every call and is judged on the rights of its user over it. What the door adds is only where the token
 * comes from - until now a person copied it out of the editor by hand.
 *
 * Two properties of this class are load-bearing and easy to undo by accident:
 *
 * 1. **No `#[DtoType]`.** The attribute would auto-wire a typed Request, and the core publishes a pair of
 *    stock sub-methods for every controller that carries one. `AgentCest` holds the published surface as a
 *    closed list and says in as many words that the same attribute must not be added to
 *    {@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\Agent}. Parameters are therefore taken
 *    scalar, the way `connectAction()` takes none at all.
 * 2. **`AgentTokenFilter` is removed per action, not per class.** The class still inherits it, so an action
 *    added here without a line in {@see self::configureActions()} demands a contour token and simply does
 *    not work - a failure that shows up on the first call. The reverse default would open a door quietly.
 * 3. **What replaces it is {@see EntryAdmissionFilter}, not nothing.** Removing the token filter alone
 *    would admit the token too, and an action that issues tokens must be the one place a token cannot
 *    reach: otherwise a token of template A leaves here holding one for template B.
 *
 * `AiAvailabilityFilter` is NOT removed: a portal with the external agent switched off refuses both actions
 * before either is entered, and that refusal is what tells a caller the feature is unavailable rather than
 * their rights insufficient.
 */
final class Session extends AbstractAgentRestController
{
	public function configureActions(): array
	{
		$entryAdmission = ['+prefilters' => [new EntryAdmissionFilter()]];

		return [
			'get' => ['-prefilters' => [AgentTokenFilter::class], ...$entryAdmission],
			'add' => ['-prefilters' => [AgentTokenFilter::class], ...$entryAdmission],
		];
	}

	/**
	 * Whether a session over $templateId may be opened, and whether one is already open.
	 *
	 * Reading it costs nothing and changes nothing, which is what makes it the probe a caller runs first:
	 * the three ways this can refuse are distinguishable, and a caller need not open a session to learn
	 * that it may not.
	 *
	 * @restMethod bizprocdesigner.agent.session.get
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 */
	public function getAction(int $templateId): SessionResponse
	{
		$userId = $this->authorize($templateId);
		$status = (new AgentWebhookService())->getStatus($userId, $templateId);

		return $this->respond($templateId, (bool)$status['connected'], $status['expiresAt']);
	}

	/**
	 * Opens a session over $templateId and answers with the base URL of the contour.
	 *
	 * Idempotent by way of the service: an active token of the same (user, template) pair is returned as it
	 * is rather than replaced, so a caller may open a session before every chain of calls and never
	 * accumulate tokens.
	 *
	 * @restMethod bizprocdesigner.agent.session.add
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 * @throws EntryOperationFailedException
	 */
	public function addAction(int $templateId): SessionResponse
	{
		$userId = $this->authorize($templateId);

		$result = (new AgentWebhookService())->ensureWebhookUrl($userId, $templateId);
		$data = $result->getData();
		if (!$result->isSuccess() || ($data['url'] ?? '') === '')
		{
			// Nothing about the request was wrong — the token store did not hand over an endpoint. Answered
			// as a failure of its own rather than as a session that is merely "not connected": the latter is
			// a legitimate state, and a caller reading it would retry forever against a portal that cannot
			// open one.
			throw EntryOperationFailedException::ofSessionResult($result);
		}

		return $this->respond(
			$templateId,
			(bool)$data['connected'],
			$data['expiresAt'],
			$this->contourBaseUrl((string)$data['url']),
		);
	}

	/**
	 * The admission of both actions, in the shape this transport answers refusals with.
	 *
	 * A verdict of FeatureUnavailable is reachable here only in a race with the switch itself - the filter
	 * ahead of the action answers that case - and it is mapped to the same exception, so the two paths
	 * cannot disagree about what a portal without the feature answers.
	 *
	 * @return int the id of the acting user
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 * @throws FeatureUnavailableException
	 */
	private function authorize(int $templateId): int
	{
		$userId = (int)CurrentUser::get()->getId();
		$result = (new AgentEntryAuthorizationService())->authorizeTemplate($userId, $templateId);

		if ($result->isAllowed())
		{
			return $userId;
		}

		if ($result->verdict === AgentEntryVerdict::TemplateNotFound)
		{
			throw new EntityNotFoundException($templateId);
		}

		if ($result->verdict === AgentEntryVerdict::FeatureUnavailable)
		{
			throw new FeatureUnavailableException();
		}

		throw new AccessDeniedException();
	}

	/**
	 * The base URL an agent addresses the contour with. The service hands over the classic
	 * /rest/<userId>/<token>/ endpoint; the V3 router lives one segment deeper, and this contour answers
	 * only there.
	 *
	 * The same rewrite the editor performs for the copied command
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Diagram::buildConnectResponse()}); it is
	 * repeated rather than shared because the two produce different things - a command line there, a base
	 * URL here - and the shared part is one substitution.
	 */
	private function contourBaseUrl(string $webhookUrl): string
	{
		$base = rtrim($webhookUrl, '/');

		return (string)preg_replace('#/rest/#', '/rest/api/', $base, 1) . '/';
	}

	private function respond(
		int $templateId,
		bool $connected,
		?int $expiresAt,
		?string $baseUrl = null,
	): SessionResponse
	{
		$item = new AgentSessionDto();
		$item->templateId = $templateId;
		$item->connected = $connected;
		$item->expiresAt = $expiresAt;
		$item->baseUrl = $baseUrl;

		return new SessionResponse($item);
	}
}
