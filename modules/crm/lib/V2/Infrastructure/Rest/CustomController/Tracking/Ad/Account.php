<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking\Ad;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\AdAccountDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\AdAccountRequest;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Binding;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Refusal;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Crm\V2\Public\Command\Tracking\Ad\ChangeAccount;
use Bitrix\Crm\V2\Public\Provider\Tracking\Ad\Accounts;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(AdAccountDto::class)]
final class Account extends RestController
{
	public function __construct(
		?Request $request = null,
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly Accounts $accounts = new Accounts(),
		private readonly ChangeAccount $change = new ChangeAccount(),
	)
	{
		parent::__construct($request);
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_tracking')]
	public function authorizationAction(AdAccountRequest $request): GetResponse
	{
		$userId = $this->getUserId();
		$this->assertAccess($userId, true);
		try
		{
			$url = $this->accounts->authorize($userId, $request->getSourceId());
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (\DomainException $exception)
		{
			self::refuse($exception);
		}
		$item = new AdAuthorizationDto();
		$item->url = $url;

		return new GetResponse($item);
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_tracking')]
	public function listAction(AdAccountRequest $request): ListResponse
	{
		$userId = $this->getUserId();
		$this->assertAccess($userId, false);
		try
		{
			$accounts = $this->accounts->list($userId, $request->getSourceId());
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (\DomainException $exception)
		{
			self::refuse($exception);
		}
		$items = new DtoCollection(AdAccountDto::class);
		foreach ($accounts as $account)
		{
			$item = new AdAccountDto();
			foreach (AdAccountDto::OUTPUT_FIELDS as $field)
			{
				$item->{$field} = $account[$field];
			}
			$items->add($item);
		}

		return new ListResponse($items);
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_tracking')]
	public function connectAction(AdAccountRequest $request): GetResponse
	{
		$userId = $this->getUserId();
		$this->assertAccess($userId, true);
		$sourceId = $request->getSourceId();
		$account = $request->getAccount();
		try
		{
			$binding = $this->change->execute($userId, $sourceId, $account['clientId'], $account['accountId']);
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (\DomainException $exception)
		{
			self::refuse($exception);
		}

		return new GetResponse($this->bindingDto($binding));
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_tracking')]
	public function disconnectAction(AdAccountRequest $request): GetResponse
	{
		$userId = $this->getUserId();
		$this->assertAccess($userId, true);
		try
		{
			$binding = $this->change->execute($userId, $request->getSourceId());
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (\DomainException $exception)
		{
			self::refuse($exception);
		}

		return new GetResponse($this->bindingDto($binding));
	}

	private function getUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}

	private function assertAccess(int $userId, bool $modify): void
	{
		try
		{
			if ($modify)
			{
				$this->access->assertCanModify($userId);
			}
			else
			{
				$this->access->assertCanRead($userId);
			}
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
	}

	private function bindingDto(Binding $binding): AdBindingDto
	{
		$item = new AdBindingDto();
		$item->sourceId = $binding->sourceId;
		$item->provider = $binding->provider;
		$item->clientId = $binding->clientId === '' ? null : $binding->clientId;
		$item->accountId = $binding->accountId;

		return $item;
	}

	public static function refuse(\DomainException $exception): never
	{
		$reason = Refusal::tryFrom($exception->getMessage());
		if ($reason === Refusal::AccessDenied)
		{
			throw new AccessDeniedException();
		}
		if ($reason === Refusal::SourceNotFound)
		{
			throw new EntityNotFoundException(0);
		}
		$reason ??= Refusal::RemoteError;
		throw new class($reason) extends RestException implements SkipWriteToLogException
		{
			public function __construct(private readonly Refusal $reason)
			{
				parent::__construct();
			}

			public function getRegistryCode(): string
			{
				return $this->reason->value;
			}

			protected function getMessagePhraseCode(): string
			{
				return $this->reason->value;
			}
		};
	}
}
