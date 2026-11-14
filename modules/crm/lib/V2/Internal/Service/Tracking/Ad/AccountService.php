<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking\Ad;

use Bitrix\Crm\Tracking\Internals\SourceTable;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Binding;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Refusal;
use Bitrix\Crm\V2\Internal\Integration\Seo\TrackingAdsGateway;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Crm\V2\Internal\Repository\Tracking\Ad\AuthorizationTicketRepository;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

final class AccountService
{
	private readonly Connection $connection;

	public function __construct(
		?Connection $connection = null,
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly TrackingAdsGateway $gateway = new TrackingAdsGateway(),
	)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	public function binding(int $userId, int $sourceId): Binding
	{
		$this->access->assertCanRead($userId);

		return $this->readBinding($sourceId);
	}

	public function authorize(int $userId, int $sourceId): string
	{
		$this->access->assertCanModify($userId);
		$binding = $this->readBinding($sourceId);
		$this->assertActive($binding);

		$this->gateway->assertAuthorizationAvailable($binding);
		$ticket = (new AuthorizationTicketRepository($this->connection))->issue(
			$userId, $sourceId, new \DateTimeImmutable(),
		);

		return (string)\Bitrix\Main\Engine\UrlManager::getInstance()->create(
			\Bitrix\Crm\Controller\Tracking\Ad\Authorization::ACTION, ['ticket' => $ticket],
		);
	}

	public function authorizeBrowser(int $userId, string $ticket, string $sessionHash): string
	{
		$this->access->assertCanModify($userId);
		if (!preg_match('/^[a-zA-Z0-9_-]{43}$/D', $ticket))
		{
			throw new \DomainException('Invalid authorization ticket');
		}
		$repository = new AuthorizationTicketRepository($this->connection);
		$hash = hash('sha256', $ticket);
		$now = new \DateTimeImmutable();
		$binding = null;
		$repository->consume($hash, $userId, $sessionHash, $now,
			function (int $sourceId) use ($userId, &$binding): void {
				if (!\Bitrix\Crm\Feature::enabled(\Bitrix\Crm\Feature\RestV3CrudDeal::class)
					|| !\Bitrix\Crm\Feature::enabled(\Bitrix\Crm\Feature\RestV3Tracking::class))
				{
					throw Refusal::AccessDenied->exception();
				}
				$this->access->assertCanModify($userId);
				$row = $this->connection->query('SELECT ID, CODE, ACTIVE, AD_CLIENT_ID, AD_ACCOUNT_ID'
					. ' FROM b_crm_tracking_source WHERE ID = ' . $sourceId . ' FOR UPDATE')->fetch();
				if (!$row)
				{
					throw Refusal::SourceNotFound->exception();
				}
				$binding = $this->normalize($row);
				$this->assertActive($binding);
			},
		);

		return $this->gateway->authorize($binding);
	}

	public function list(int $userId, int $sourceId): array
	{
		return $this->gateway->list($this->binding($userId, $sourceId));
	}

	/** Remote validation finishes before the command starts its lifecycle transaction. */
	public function prepareChange(int $userId, int $sourceId, ?string $clientId, ?string $accountId): Binding
	{
		$this->access->assertCanModify($userId);
		$binding = $this->readBinding($sourceId);
		if ($clientId !== null)
		{
			$this->assertActive($binding);
			$this->gateway->validateAccount($binding, $clientId, $accountId);
		}
		elseif ($accountId !== null)
		{
			throw Refusal::AccountUnavailable->exception();
		}

		return $binding;
	}

	/** Caller owns the transaction; the source row serializes concurrent binding changes. */
	public function applyChange(Binding $expected, ?string $clientId, ?string $accountId): Binding
	{
		$row = $this->connection->query('SELECT ID, CODE, ACTIVE, AD_CLIENT_ID, AD_ACCOUNT_ID'
			. ' FROM b_crm_tracking_source WHERE ID = ' . $expected->sourceId . ' FOR UPDATE')->fetch();
		if (!$row)
		{
			throw Refusal::SourceNotFound->exception();
		}
		$current = $this->normalize($row);
		if (!$expected->equals($current))
		{
			throw Refusal::OperationConflict->exception();
		}
		$clientId ??= '';
		if ($current->clientId === $clientId && $current->accountId === $accountId)
		{
			return $current;
		}
		$result = SourceTable::update($current->sourceId, [
			'AD_CLIENT_ID' => $clientId,
			'AD_ACCOUNT_ID' => $accountId ?? '',
		]);
		if (!$result->isSuccess())
		{
			throw Refusal::OperationConflict->exception();
		}
		return new Binding($current->sourceId, $current->code, $current->provider,
			$clientId, $accountId, $current->active);
	}

	private function readBinding(int $sourceId): Binding
	{
		if ($sourceId < 1 || $sourceId > 2147483647)
		{
			throw Refusal::SourceNotFound->exception();
		}
		$row = $this->connection->query('SELECT ID, CODE, ACTIVE, AD_CLIENT_ID, AD_ACCOUNT_ID'
			. ' FROM b_crm_tracking_source WHERE ID = ' . $sourceId)->fetch();
		if (!$row)
		{
			throw Refusal::SourceNotFound->exception();
		}

		return $this->normalize($row);
	}

	private function normalize(array $row): Binding
	{
		return new Binding(
			(int)$row['ID'],
			(string)$row['CODE'],
			$this->gateway->provider((string)$row['CODE']),
			(string)$row['AD_CLIENT_ID'],
			$row['AD_ACCOUNT_ID'] === null || $row['AD_ACCOUNT_ID'] === '' ? null : (string)$row['AD_ACCOUNT_ID'],
			$row['ACTIVE'] === 'Y',
		);
	}

	private function assertActive(Binding $binding): void
	{
		if (!$binding->active)
		{
			throw Refusal::SourceArchived->exception();
		}
	}
}
