<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\ContourCaller;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentTokenGuard;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

/**
 * Judges the credential of an action of the entry: an ordinary key passes, a credential of the contour
 * never does.
 *
 * This is what keeps a token bound to one template for one day. The three actions of the entry take no
 * token by design ({@see AgentTokenFilter} is removed from them), and without this filter that removal
 * admits the token as well: a token of template A calls agent.session.add for template B, receives a
 * token for B, and the binding it was issued under is gone. The same path reaches template.add and mints
 * templates the token was never scoped to.
 *
 * The refusal does not ask whether the token is still good. An expired or revoked token of the contour is
 * still a credential of one, and a caller holding it is precisely the caller that retries - so validity
 * is not consulted at all {@see AgentTokenGuard::isContourCredential()}.
 *
 * An ordinary incoming webhook - one that is not ours, a user webhook among them - is an application key
 * to {@see ContourCaller} and passes as one: it carries no binding of the contour, so there is nothing here
 * for it to escalate, and its rights are judged per template the same way. A legacy token of the agent is
 * not such a key: ContourCaller refuses it, and so does this filter.
 */
final class EntryAdmissionFilter extends Base
{
	private readonly AgentTokenGuard $guard;

	public function __construct(?AgentTokenGuard $guard = null)
	{
		parent::__construct();

		$this->guard = $guard ?? AgentTokenGuard::forCurrentRequest();
	}

	/**
	 * @throws AccessDeniedException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$server = $this->server();

		return match (ContourCaller::of($server, $this->guard))
		{
			ContourCaller::Application => null,

			ContourCaller::Contour => $this->judgeWebhook($server?->getPasswordId()),

			ContourCaller::Unsupported => throw new AccessDeniedException(),
		};
	}

	/**
	 * @throws AccessDeniedException
	 */
	private function judgeWebhook(?string $passwordId): ?EventResult
	{
		if ($this->guard->isContourCredential($passwordId))
		{
			throw new AccessDeniedException();
		}

		return null;
	}

	/**
	 * The server the call arrived on, or null outside the REST transport - which the admission above
	 * answers as a refusal, the entry being addressable no other way.
	 */
	private function server(): ?\CRestServer
	{
		$controller = $this->getAction()?->getController();

		return $controller instanceof RestController ? $controller->getServer() : null;
	}
}
