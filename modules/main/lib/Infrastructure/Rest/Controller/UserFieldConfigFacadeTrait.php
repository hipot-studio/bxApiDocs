<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller;

use Bitrix\Main;
use Bitrix\Main\Infrastructure\Rest\Service\UserFieldConfigFacade;
use Bitrix\Rest\V3\Exception;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * Shared facade call path of the user field configuration controllers: the facade speaks the
 * language of the domain, the controllers answer in REST exceptions.
 */
trait UserFieldConfigFacadeTrait
{
	protected function executeFacade(callable $operation): Main\Result
	{
		try
		{
			$result = $operation();
		}
		catch (Main\Infrastructure\Rest\Exception\InsufficientScopeException $exception)
		{
			throw new Exception\InsufficientScopeException($exception);
		}
		catch (Main\AccessDeniedException $exception)
		{
			throw new Exception\AccessDeniedException($exception);
		}
		catch (Main\ObjectNotFoundException $exception)
		{
			throw new RequestValidationException([
				new Main\Error($exception->getMessage(), 'moduleId'),
			]);
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return $result;
	}

	/**
	 * @see AbstractController::getRequiredServer() — the trait is used by its heirs only.
	 */
	protected function getAuthScopes(): array
	{
		return $this->getRequiredServer()->getAuthScope();
	}

	protected function getFacade(): UserFieldConfigFacade
	{
		return new UserFieldConfigFacade();
	}
}
