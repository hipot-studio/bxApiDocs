<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Rest;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetMarkupPackageDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSiteModeDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Exception\MarkupRequestRefusedException;
use Bitrix\Landing\Integration\AiAssistant\Log;
use Bitrix\Landing\Integration\AiAssistant\Service\MarkupRequestAuthorizer;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetMarkupPackageHandler;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Site\Type;

/**
 * Reading one whole markup package file over REST for the Marta bridge: the file of the current package
 * version comes in one answer as base64 instead of the model-sized parts of get_markup_package. The caller
 * and the availability are answered by the authorizer the intake shares, the page and the edit right by
 * the edit target resolver of the binding, as for get_markup_package.
 *
 * Only the binding and the path are taken. Every refusal is a MarkupRequestRefusedException with a string
 * code; a page without a stored version or a stored file that cannot be read whole is a failure of the
 * system, not a refusal. Read-only: nothing is written or locked.
 */
class GetMarkupFileRestAction
{
	private const METHOD = 'landing.markup.getfile';

	private const ACCEPTED_FIELDS = [
		AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD,
		'path',
	];

	/**
	 * @return array{path: string, content: string, hash: string, size: int}
	 *
	 * @throws MarkupRequestRefusedException
	 */
	public function execute(array $fields): array
	{
		$fields = array_intersect_key($fields, array_flip(self::ACCEPTED_FIELDS));
		$userId = $this->getUserId();
		if (!$this->isLogEnabled())
		{
			return $this->read($fields, $userId);
		}

		$log = $this->createLog();
		$log->addToolCall(self::METHOD, static::class, $userId, $userId, $fields);
		try
		{
			$answer = $this->read($fields, $userId);
		}
		catch (MarkupRequestRefusedException $exception)
		{
			$log->addToolResult(self::METHOD, static::class, $userId, $userId, 'failed', [$exception->getErrorCode()]);

			throw $exception;
		}
		catch (\Throwable $exception)
		{
			$log->addToolResult(self::METHOD, static::class, $userId, $userId, 'exception', [$exception->getMessage()]);

			throw $exception;
		}
		$log->addToolResult(self::METHOD, static::class, $userId, $userId, 'ok');

		return $answer;
	}

	protected function getRestAuthType(): ?string
	{
		// on the AJAX path of the public actions there is no REST server at all
		if (!class_exists(\CRestServer::class))
		{
			return null;
		}

		return \CRestServer::instance()?->getAuthType();
	}

	protected function leaveSiteScope(): void
	{
		Type::clearScope();
	}

	protected function createAuthorizer(): MarkupRequestAuthorizer
	{
		return new MarkupRequestAuthorizer();
	}

	protected function createHandler(): GetMarkupPackageHandler
	{
		return new GetMarkupPackageHandler();
	}

	protected function getUserId(): int
	{
		return Manager::getUserId();
	}

	protected function isLogEnabled(): bool
	{
		return Log::isEnabled();
	}

	protected function createLog(): Log
	{
		return new Log();
	}

	/**
	 * @throws MarkupRequestRefusedException
	 */
	private function read(array $fields, int $userId): array
	{
		$authorizer = $this->createAuthorizer();
		$authorizer->assertBridgeCaller($this->getRestAuthType());

		// the REST gateway enters the site scope named in the request, and the edit right is answered
		// for that section; the read tool runs with no scope, so the right here is asked with no scope too
		$this->leaveSiteScope();
		$authorizer->assertMarkupAvailable();

		try
		{
			return $this->createHandler()->readWholeFile(GetMarkupPackageDto::fromArray($fields), $userId);
		}
		catch (AiSiteModeDisabledException $exception)
		{
			throw new MarkupRequestRefusedException(
				$exception->getMessage(),
				MarkupRequestRefusedException::MODE_DISABLED,
				$exception,
			);
		}
	}
}
