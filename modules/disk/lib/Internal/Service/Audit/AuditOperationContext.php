<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Entity\Audit\AuditChannel as EntityAuditChannel;
use InvalidArgumentException;
use LogicException;

final class AuditOperationContext
{
	public const ACTION_DOMAIN = 'domain';
	public const ACTION_EXPLICIT_COPY = 'explicit_copy';
	public const ACTION_EXPLICIT_MOVE = 'explicit_move';
	public const ACTION_EXPLICIT_MASS_OPERATION = 'explicit_mass_operation';
	public const ACTION_CASCADE_OPERATION = 'cascade_operation';
	public const ACTION_CROSS_STORAGE_MOVE = 'cross_storage_move';

	/**
	 * @param string[] $selectedTargetIds
	 */
	public function __construct(
		public readonly string $operationId,
		public readonly string $actionKind = self::ACTION_DOMAIN,
		public readonly ?string $channel = null,
		public readonly ?AuditActionContext $actionContext = null,
		public readonly ?string $cascadeRootTargetId = null,
		public readonly array $selectedTargetIds = [],
	)
	{
		$this->assertNotEmpty($operationId, 'operationId');
		$this->assertKnownActionKind($actionKind);

		if ($channel !== null)
		{
			EntityAuditChannel::assertKnown($channel);
		}
		if ($channel !== null && $actionContext !== null && $channel !== $actionContext->channel)
		{
			throw new InvalidArgumentException('Audit operation channel must match its action context.');
		}

		foreach ($selectedTargetIds as $selectedTargetId)
		{
			$this->assertNotEmpty((string)$selectedTargetId, 'selectedTargetIds');
		}
	}

	/**
	 * @param int[]|string[] $selectedTargetIds
	 */
	public static function create(
		string $actionKind = self::ACTION_DOMAIN,
		?string $operationId = null,
		?string $channel = null,
		?AuditActionContext $actionContext = null,
		?string $cascadeRootTargetId = null,
		array $selectedTargetIds = [],
	): self
	{
		return new self(
			operationId: $operationId ?? AuditOperationContextStack::createOperationId(),
			actionKind: $actionKind,
			channel: $channel ?? $actionContext?->channel,
			actionContext: $actionContext,
			cascadeRootTargetId: $cascadeRootTargetId,
			selectedTargetIds: array_map('strval', $selectedTargetIds),
		);
	}

	public static function createForSystem(
		?string $operationId = null,
		?array $externalContext = null,
	): self
	{
		return self::create(
			operationId: $operationId,
			actionContext: (new AuditActionContextResolver())->resolveForSystem($externalContext),
		);
	}

	public function joinBoundary(self $boundary): self
	{
		$boundaryChannel = $boundary->channel ?? $boundary->actionContext?->channel;
		$nestedChannel = $this->channel ?? $this->actionContext?->channel;
		if ($boundaryChannel !== null && $nestedChannel !== null && $boundaryChannel !== $nestedChannel)
		{
			throw new LogicException('A nested audit operation cannot change the command channel.');
		}

		$actionContext = $this->actionContext ?? $boundary->actionContext;
		if ($this->actionContext !== null && $boundary->actionContext !== null)
		{
			$this->assertSameInitiator($boundary->actionContext, $this->actionContext);
		}

		return new self(
			operationId: $boundary->operationId,
			actionKind: $this->actionKind,
			channel: $boundaryChannel ?? $nestedChannel,
			actionContext: $actionContext,
			cascadeRootTargetId: $this->cascadeRootTargetId,
			selectedTargetIds: $this->selectedTargetIds,
		);
	}

	public function hasSelectedTarget(string $targetId): bool
	{
		if ($this->selectedTargetIds === [])
		{
			return true;
		}

		return in_array($targetId, $this->selectedTargetIds, true);
	}

	private function assertKnownActionKind(string $actionKind): void
	{
		if (!in_array(
			$actionKind,
			[
				self::ACTION_DOMAIN,
				self::ACTION_EXPLICIT_COPY,
				self::ACTION_EXPLICIT_MOVE,
				self::ACTION_EXPLICIT_MASS_OPERATION,
				self::ACTION_CASCADE_OPERATION,
				self::ACTION_CROSS_STORAGE_MOVE,
			],
			true,
		))
		{
			throw new InvalidArgumentException('Unknown disk audit operation action kind.');
		}
	}

	private function assertSameInitiator(AuditActionContext $boundary, AuditActionContext $nested): void
	{
		if (
			$boundary->initiatorType !== $nested->initiatorType
			|| $boundary->initiatorId !== $nested->initiatorId
		)
		{
			throw new LogicException('A nested audit operation cannot change the command initiator.');
		}
	}

	private function assertNotEmpty(string $value, string $field): void
	{
		if ($value === '')
		{
			throw new InvalidArgumentException("Disk audit operation context field {$field} is required.");
		}
	}
}
