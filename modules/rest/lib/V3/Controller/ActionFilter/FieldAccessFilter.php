<?php

namespace Bitrix\Rest\V3\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Access\FieldAccessContext;
use Bitrix\Rest\V3\Access\FieldAccessProvider;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Runs the access provider declared by the module for the field methods of a resource.
 *
 * The filter holds no policy of its own: it only builds the request context and turns a
 * refusal of the provider into an access error. A method without a declared provider is
 * left to the scope check alone.
 *
 * Fail-closed: the filter is attached to the field controllers only, so reaching one of
 * them without a method description means a broken call chain and the request is denied.
 * A guarded method reached without an authenticated user is denied for the same reason,
 * instead of asking the provider about user zero.
 *
 * The single call the provider is not asked about is one the idempotency filter has already
 * cancelled to replay a stored response @see isActionCancelledByIdempotentReplay().
 */
final class FieldAccessFilter extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		$action = $this->getAction();
		/** @var RestController $controller */
		$controller = $action->getController();

		$description = $controller->getMethodDescription();
		if ($description === null)
		{
			throw new AccessDeniedException(
				new SystemException('A field method is running without a schema description of the method.'),
			);
		}

		if ($description->accessProvider === null)
		{
			return null;
		}

		$userId = (int)$controller->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			throw new AccessDeniedException(
				new SystemException('A guarded field method is running without an authenticated user.'),
			);
		}

		if ($this->isActionCancelledByIdempotentReplay($event))
		{
			return null;
		}

		$arguments = $action->getArguments();
		$context = new FieldAccessContext(
			userId: $userId,
			module: $description->module,
			actionUri: $description->actionUri,
			action: $action->getName(),
			dtoFqcn: $description->dtoFqcn,
			resourceDtoFqcn: $description->resourceDtoFqcn,
			entityId: $this->resolveEntityId($description, $arguments),
			arguments: $arguments,
		);

		$decision = $this->createProvider($description->accessProvider)->decide($context);
		if (!$decision->isSuccess())
		{
			throw new AccessDeniedException(new SystemException(implode('; ', $decision->getErrorMessages())));
		}

		return null;
	}

	/**
	 * A repeated call carrying an Idempotency-Key that already has a stored response never reaches
	 * the action: IdempotencyFilter cancels it with an error result and its post filter puts the
	 * stored response in place of the one the action would have produced. Asking the provider then
	 * would refuse the repeat of a successful delete, because the object of the call is gone by now
	 * and the exception would leave Controller::run before the stored response is substituted.
	 * Skipping the check opens nothing: the cancelled action stays unreachable either way.
	 *
	 * A cancelled call still runs the pre filters after the one that cancelled it, so the state is
	 * read from the event: the engine appends the result of every handler as it runs, and
	 * IdempotencyFilter runs before this one - RestController attaches it, the field controllers
	 * append this filter after. An error result of that filter means the replay and only the
	 * replay: it is the single branch where it cancels the action.
	 */
	private function isActionCancelledByIdempotentReplay(Event $event): bool
	{
		$action = $this->getAction();

		foreach ($event->getResults() as $result)
		{
			$handler = $result->getHandler();
			if (
				$result->getType() === EventResult::ERROR
				&& $handler instanceof IdempotencyFilter
				&& $handler->getAction() === $action
			)
			{
				return true;
			}
		}

		return false;
	}

	private function createProvider(string $providerClass): FieldAccessProvider
	{
		// the schema is cached, so the declaration can outlive the class it names
		if (!is_subclass_of($providerClass, FieldAccessProvider::class))
		{
			throw new AccessDeniedException(
				new SystemException("Access provider '{$providerClass}' is not a " . FieldAccessProvider::class . '.'),
			);
		}

		return new $providerClass();
	}

	/**
	 * The entity id reaches an action in three shapes: a route parameter (custom list/get/delete),
	 * a request property (enum list/delete) and a body field (add/update). Arguments come first:
	 * they hold the value the action itself works with. Query params of the schema are the last
	 * resort: enum.get resolves the entity by the enum value and takes no entity id at all.
	 */
	private function resolveEntityId(MethodDescription $description, array $arguments): ?string
	{
		if (is_string($arguments['entityId'] ?? null))
		{
			return $arguments['entityId'];
		}

		foreach ($arguments as $argument)
		{
			if ($argument instanceof Request)
			{
				$entityId = $this->getEntityIdFromRequest($argument);
				if ($entityId !== null)
				{
					return $entityId;
				}
			}
		}

		$queryParams = $description->queryParams ?? [];
		$declaredEntityId = $queryParams['entityId'] ?? $queryParams['fields']['entityId'] ?? null;

		return is_string($declaredEntityId) ? $declaredEntityId : null;
	}

	private function getEntityIdFromRequest(Request $request): ?string
	{
		if (isset($request->entityId) && is_string($request->entityId))
		{
			return $request->entityId;
		}

		if (isset($request->fields) && $request->fields instanceof FieldsStructure)
		{
			$entityId = $request->fields->getItems()['entityId'] ?? null;
			if (is_string($entityId))
			{
				return $entityId;
			}
		}

		return null;
	}
}
