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
 * Judges the authorization of every action of the contour, before the action is reached.
 *
 * A pre-filter rather than a step of an action, because an action is not always run: a repeat under an
 * idempotency key is answered from a stored response, and a key reused under a changed body ends the call
 * outright. Standing ahead of that filter is what makes the token judged on every path an action can take
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\AbstractAgentRestController::getDefaultPreFilters()}).
 *
 * Two callers are admitted, not one. A token of the contour is the original: bound to a template,
 * expiring within the day, minted from the editor. An application key is the second - an OAuth application
 * or any incoming webhook that is not a token of ours, a user webhook among them - and it carries no binding
 * at all, which is why admitting it here decides nothing about WHICH template the call may touch. That
 * question moves wholly into the action, where manageableTemplate() answers it on every call, on the rights
 * of the user the call runs as.
 *
 * The admission is a whitelist of exactly two authorization types and never "there is no token, so it
 * must be an application key". The password id is empty under an application key - but it is equally
 * empty under a portal session and under the authorization types of im, pull, call, imopenlines and
 * aiassistant, so the negative reading would let all of them into the contour. Within a webhook it is the
 * owner marker that tells a token from a key, not its validity: a dead token stays a token and is refused
 * here, and a webhook carrying no password id is refused outright {@see ContourCaller}.
 *
 * It holds the nine actions of the contour, not everything the module publishes: the stock field.get and
 * field.list the core adds for every controller carrying #[DtoType] are answered by
 * {@see \Bitrix\Rest\V3\Realisation\Controller\Field} and reach no pre-filter of the owning controller.
 *
 * Judged here of a TOKEN is what it answers for on its own: ours, active, not expired, still bound to a
 * template. Whether the binding is the template the call asks for stays in the action, where the requested
 * identifier is known. The refusal is the AccessDeniedException of every unauthorized call: moving a check
 * earlier must not make the state of a token readable off the status of the response.
 */
final class AgentTokenFilter extends Base
{
	/**
	 * @throws AccessDeniedException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$server = $this->server();

		return match (ContourCaller::of($server))
		{
			// An application key passes without a binding being judged - it has none. It will have to name
			// the template it means, and manageableTemplate() will judge that - here there is nothing
			// about a template to judge.
			ContourCaller::Application => null,

			ContourCaller::Contour => $this->judgeContourToken($server?->getPasswordId()),

			ContourCaller::Unsupported => throw new AccessDeniedException(),
		};
	}

	/**
	 * @throws AccessDeniedException
	 */
	private function judgeContourToken(?string $passwordId): ?EventResult
	{
		if (AgentTokenGuard::forCurrentRequest()->getBoundTemplateId($passwordId) === null)
		{
			throw new AccessDeniedException();
		}

		return null;
	}

	/**
	 * The server the call arrived on, or null when there is none - a controller reached outside the REST
	 * transport. Null is a refusal: the contour is not addressable that way.
	 */
	private function server(): ?\CRestServer
	{
		$controller = $this->getAction()?->getController();

		return $controller instanceof RestController ? $controller->getServer() : null;
	}
}
