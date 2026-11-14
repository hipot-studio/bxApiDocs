<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Entity\Audit\AuditChannel;
use Bitrix\Disk\Internal\Entity\Audit\AuditContext;
use Bitrix\Disk\Internal\Entity\Audit\AuditInitiator;
use Bitrix\Disk\SystemUser;
use Bitrix\Disk\User;
use Closure;
use InvalidArgumentException;

final class AuditActionContextResolver
{
	private readonly Closure $userNameResolver;

	public function __construct(?Closure $userNameResolver = null)
	{
		$this->userNameResolver = $userNameResolver ?? static function (int $userId): ?string {
			$name = User::loadById($userId)?->getFormattedName();

			return is_string($name) ? $name : null;
		};
	}

	public function resolveForWeb(
		?int $userId,
		?string $ip = null,
		?array $externalContext = null,
	): AuditActionContext
	{
		return $this->resolveForChannel(AuditChannel::WEB, $userId, ip: $ip, externalContext: $externalContext);
	}

	public function resolveForRest(
		?int $userId,
		?array $application = null,
		?string $ip = null,
		?array $externalContext = null,
	): AuditActionContext
	{
		return $this->resolveForChannel(
			AuditChannel::API,
			$userId,
			application: $application,
			ip: $ip,
			externalContext: $externalContext,
		);
	}

	public function resolveForDesktop(
		?int $userId,
		?string $ip = null,
		?array $externalContext = null,
	): AuditActionContext
	{
		return $this->resolveForChannel(AuditChannel::DESKTOP, $userId, ip: $ip, externalContext: $externalContext);
	}

	public function resolveForMobile(
		?int $userId,
		?string $ip = null,
		?array $externalContext = null,
	): AuditActionContext
	{
		return $this->resolveForChannel(AuditChannel::MOBILE, $userId, ip: $ip, externalContext: $externalContext);
	}

	public function resolveForSystem(?array $externalContext = null): AuditActionContext
	{
		return $this->resolveForChannel(AuditChannel::SYSTEM, null, externalContext: $externalContext);
	}

	public function resolveForBizproc(?int $userId, ?array $externalContext = null): AuditActionContext
	{
		return $this->resolveForChannel(AuditChannel::BIZPROC, $userId, externalContext: $externalContext);
	}

	public function resolveForSystemActor(?int $userId, ?array $externalContext = null): AuditActionContext
	{
		$context = $this->normalizeExternalContext($externalContext);
		$resolvedUserId = $this->resolveUserId($userId);

		return new AuditActionContext(
			initiatorType: $resolvedUserId > 0 ? AuditInitiator::TYPE_EMPLOYEE : AuditInitiator::TYPE_SYSTEM,
			initiatorId: $resolvedUserId > 0 ? (string)$resolvedUserId : (string)SystemUser::SYSTEM_USER_ID,
			initiatorName: $resolvedUserId > 0 ? $this->resolveUserName($resolvedUserId) : 'System',
			channel: AuditChannel::SYSTEM,
			contextType: $context['type'],
			contextEntityType: $context['entityType'],
			contextEntityId: $context['entityId'],
			contextEntityName: $context['entityName'],
		);
	}

	public function resolve(
		?int $userId,
		?string $channel = null,
		?array $application = null,
		?string $ip = null,
		?array $externalContext = null,
	): AuditActionContext
	{
		$resolvedChannel = $channel ?? AuditChannel::WEB;

		return $this->resolveForChannel(
			channel: $resolvedChannel,
			userId: $userId,
			application: $application,
			ip: $ip,
			externalContext: $externalContext,
		);
	}

	public function resolveForChannel(
		string $channel,
		?int $userId,
		?array $application = null,
		?string $ip = null,
		?array $externalContext = null,
	): AuditActionContext
	{
		AuditChannel::assertKnown($channel);
		$context = $this->normalizeExternalContext($externalContext);
		$initiator = $this->resolveInitiator($channel, $userId, $application);

		return new AuditActionContext(
			initiatorType: $initiator['type'],
			initiatorId: $initiator['id'],
			initiatorName: $initiator['name'],
			channel: $channel,
			contextType: $context['type'],
			ip: $ip,
			contextEntityType: $context['entityType'],
			contextEntityId: $context['entityId'],
			contextEntityName: $context['entityName'],
		);
	}

	private function resolveInitiator(string $channel, ?int $userId, ?array $application): array
	{
		if ($channel === AuditChannel::SYSTEM)
		{
			return $this->getSystemInitiator();
		}

		if ($channel === AuditChannel::API && $application !== null)
		{
			$id = $this->optionalString($application, 'id');
			if ($id !== null && trim($id) !== '')
			{
				return [
					'type' => AuditInitiator::TYPE_APPLICATION,
					'id' => $id,
					'name' => $this->optionalString($application, 'name') ?? "Application {$id}",
				];
			}
		}

		$resolvedUserId = $this->resolveUserId($userId);
		if ($resolvedUserId > 0)
		{
			return [
				'type' => AuditInitiator::TYPE_EMPLOYEE,
				'id' => (string)$resolvedUserId,
				'name' => $this->resolveUserName($resolvedUserId),
			];
		}

		return $this->getSystemInitiator();
	}

	private function getSystemInitiator(): array
	{
		return [
			'type' => AuditInitiator::TYPE_SYSTEM,
			'id' => (string)SystemUser::SYSTEM_USER_ID,
			'name' => 'System',
		];
	}

	private function normalizeExternalContext(?array $externalContext): array
	{
		return [
			'type' => $this->optionalString($externalContext ?? [], 'type') ?? AuditContext::TYPE_DISK,
			'entityType' => $this->optionalString($externalContext ?? [], 'entityType'),
			'entityId' => $this->optionalString($externalContext ?? [], 'entityId'),
			'entityName' => $this->optionalString($externalContext ?? [], 'entityName'),
		];
	}

	private function resolveUserId(?int $userId): int
	{
		if ($userId !== null && $userId > 0 && $userId !== SystemUser::SYSTEM_USER_ID)
		{
			return $userId;
		}

		return 0;
	}

	private function resolveUserName(int $userId): string
	{
		$name = ($this->userNameResolver)($userId);

		return is_string($name) && trim($name) !== '' ? $name : "User {$userId}";
	}

	private function optionalString(array $data, string $key): ?string
	{
		if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '')
		{
			return null;
		}

		if (!is_string($data[$key]) && !is_int($data[$key]))
		{
			throw new InvalidArgumentException("Audit context field {$key} must be a string.");
		}

		return (string)$data[$key];
	}
}
