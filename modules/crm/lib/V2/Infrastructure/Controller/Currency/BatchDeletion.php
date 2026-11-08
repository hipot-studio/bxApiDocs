<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Controller\Currency;

use Bitrix\Crm\V2\Internal\Access\Currency\CurrencyAdminAccess;
use Bitrix\Crm\V2\Internal\Service\Currency\BatchDeletion\CurrencyBatchDeletionService;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;

final class BatchDeletion extends Controller
{
	public const GRID_ID = 'CRM_CURRENCY_LIST';
	public const ERROR_INVALID_REQUEST = 'CRM_CURRENCY_BATCH_INVALID_REQUEST';
	public const MAX_PROCESS_TOKEN_LENGTH = 128;

	private const PROCESS_TOKEN_PATTERN = '/^[A-Za-z0-9_-]+$/D';

	private const RESPONSE_FIELDS = [
		'STATUS' => true,
		'PROCESSED_ITEMS' => true,
		'TOTAL_ITEMS' => true,
		'SUCCESSFUL_ITEMS' => true,
		'FAILED_ITEMS' => true,
		'ITEM_ERRORS' => true,
	];

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new ActionFilter\Scope(ActionFilter\Scope::AJAX),
		];
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function prepareAction(
		CurrencyBatchDeletionService $service,
		CurrencyAdminAccess $access,
	): ?array
	{
		$userId = $this->getAuthorizedUserId($access);
		$request = $this->getHttpRequest();
		if ($userId === null || $request === null)
		{
			return null;
		}

		$processToken = $this->readProcessToken($request);
		$gridId = $request->getPost('gridId');
		if (!$this->validateIdentity($gridId, $processToken))
		{
			return null;
		}

		$mode = $request->getPost('mode');
		$currencyCodes = $request->getPost('currencyCodes');
		if (
			($mode !== null && !is_string($mode))
			|| ($currencyCodes !== null && !is_array($currencyCodes))
		)
		{
			$this->addInvalidRequestError();

			return null;
		}

		$result = $service->prepare(
			$userId,
			$gridId,
			$processToken,
			$mode ?? '',
			$currencyCodes ?? [],
		);

		return $this->mapResult($result, true);
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function processAction(
		CurrencyBatchDeletionService $service,
		CurrencyAdminAccess $access,
	): ?array
	{
		$userId = $this->getAuthorizedUserId($access);
		$request = $this->getHttpRequest();
		if ($userId === null || $request === null)
		{
			return null;
		}

		$processToken = $this->readProcessToken($request);
		$gridId = $request->getPost('gridId');
		if (!$this->validateIdentity($gridId, $processToken))
		{
			return null;
		}

		return $this->mapResult($service->process($userId, $gridId, $processToken));
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function cancelAction(
		CurrencyBatchDeletionService $service,
		CurrencyAdminAccess $access,
	): ?array
	{
		$userId = $this->getAuthorizedUserId($access);
		$request = $this->getHttpRequest();
		if ($userId === null || $request === null)
		{
			return null;
		}

		$processToken = $this->readProcessToken($request);
		$gridId = $request->getPost('gridId');
		$cancelingAction = $request->getPost('cancelingAction');
		if (
			!$this->validateIdentity($gridId, $processToken)
			|| !is_string($cancelingAction)
			|| trim($cancelingAction) === ''
		)
		{
			$this->addInvalidRequestErrorIfMissing();

			return null;
		}

		return $this->mapResult($service->cancel($userId, $gridId, $processToken));
	}

	private function getAuthorizedUserId(CurrencyAdminAccess $access): ?int
	{
		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
		if (!$access->canManage($userId))
		{
			$this->addError(new Error('Access denied.', CurrencyAdminAccess::ERROR_ACCESS_DENIED));

			return null;
		}

		return $userId;
	}

	private function getHttpRequest(): ?HttpRequest
	{
		$request = $this->getRequest();
		if (!$request instanceof HttpRequest)
		{
			$this->addInvalidRequestError();

			return null;
		}

		return $request;
	}

	private function readProcessToken(HttpRequest $request): string
	{
		$processToken = $request->getPost('PROCESS_TOKEN');

		return is_string($processToken) ? $processToken : '';
	}

	private function validateIdentity(mixed $gridId, string $processToken): bool
	{
		if (
			$gridId !== self::GRID_ID
			|| strlen($processToken) > self::MAX_PROCESS_TOKEN_LENGTH
			|| preg_match(self::PROCESS_TOKEN_PATTERN, $processToken) !== 1
		)
		{
			$this->addInvalidRequestError();

			return false;
		}

		return true;
	}

	private function mapResult(Result $result, bool $allowNextAction = false): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$fields = self::RESPONSE_FIELDS;
		if ($allowNextAction)
		{
			$fields['NEXT_ACTION'] = true;
		}

		return array_intersect_key($result->getData(), $fields);
	}

	private function addInvalidRequestError(): void
	{
		$this->addError(new Error('Invalid batch deletion request.', self::ERROR_INVALID_REQUEST));
	}

	private function addInvalidRequestErrorIfMissing(): void
	{
		if ($this->getErrors() === [])
		{
			$this->addInvalidRequestError();
		}
	}
}
