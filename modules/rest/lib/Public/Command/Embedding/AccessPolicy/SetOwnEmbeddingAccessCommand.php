<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Embedding\AccessPolicy;

use Bitrix\Main;

class SetOwnEmbeddingAccessCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly array $accessCodes,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();
		try
		{
			($this->createHandler())($this);
		}
		catch (Main\AccessDeniedException)
		{
			$result->addError(new Main\Error('Access denied', 'ACCESS_DENIED'));
		}
		catch (Main\ArgumentException $exception)
		{
			$code = $exception->getParameter() === 'INVALID_ACCESS_CODES'
				? 'INVALID_ACCESS_CODES'
				: 'POLICY_SAVE_FAILED'
			;
			$result->addError(new Main\Error($code, $code));
		}
		catch (\Throwable $exception)
		{
			$auditFailed = $exception instanceof \RuntimeException
				&& $exception->getMessage() === 'POLICY_AUDIT_FAILED';
			$code = $auditFailed ? 'POLICY_AUDIT_FAILED' : 'POLICY_SAVE_FAILED';
			$message = $auditFailed ? 'Policy saved, but audit failed' : 'Policy save failed';
			$result->addError(new Main\Error($message, $code));
		}

		return $result;
	}

	protected function createHandler(): SetOwnEmbeddingAccessCommandHandler
	{
		return new SetOwnEmbeddingAccessCommandHandler();
	}

	public function toArray(): array
	{
		return ['accessCodes' => $this->accessCodes];
	}
}
