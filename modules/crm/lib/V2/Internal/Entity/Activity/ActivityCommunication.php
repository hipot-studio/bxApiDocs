<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity;

use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;

/**
 * One communication of an activity: the channel and the address the activity talks through,
 * together with the CRM entity that address belongs to (a row of `b_crm_act_comm`).
 *
 * THE ENTITY IS OPTIONAL AND THE CHANNEL IS NOT. An address nobody attributed to a CRM entity is a
 * communication legacy stores like any other - the entity columns of its row stay at zero and its
 * snapshot of the names is empty (crm_activity.php:3922-3923) - and it is not a corner case: outgoing
 * mail names the channel and the address of the addressee always, and the entity only where the caller
 * handed an `ADDRESS_SOURCE` over (integration/mailmanager.php:249-253, :292-298). Its readers already
 * expect it: `PrepareCommunicationInfos` falls back to the owner of the activity wherever either half of
 * the pair is non-positive (:4018-4022). An address with no channel is a different matter and stays
 * refused: a communication without one names nothing at all.
 *
 * The owner columns of the row are not part of the model: they are always the owner of the
 * activity itself and are filled by the repository from it. Neither is the serialized snapshot
 * of the owner names - it is a storage detail the repository maintains.
 */
final class ActivityCommunication
{
	private readonly string $type;

	private readonly string $value;

	private readonly OwnerType $entityType;

	private readonly int $entityId;

	/**
	 * @param string $type Channel of the communication as the storage names it ('PHONE', 'EMAIL', 'IM').
	 * @param string $value Address inside that channel.
	 * @param OwnerType $entityType Type of the CRM entity the address belongs to; `OwnerType::UNDEFINED`
	 *        together with a zero id where the address belongs to none.
	 * @param int $entityId Id of that entity, or zero where the address belongs to none.
	 * @throws ArgumentException on an empty channel, or on a negative entity id - which is neither an
	 *         entity nor the absence of one.
	 */
	public function __construct(string $type, string $value, OwnerType $entityType, int $entityId)
	{
		if ($type === '')
		{
			throw new ArgumentException('Communication type must not be empty');
		}
		if ($entityId < 0)
		{
			throw new ArgumentException("Communication entity ID must not be negative, got: {$entityId}");
		}

		$this->type = $type;
		$this->value = $value;
		$this->entityType = $entityType;
		$this->entityId = $entityId;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function getEntityType(): OwnerType
	{
		return $this->entityType;
	}

	public function getEntityId(): int
	{
		return $this->entityId;
	}

	/**
	 * Identity of the communication inside one activity. Row ids are not stable - the write path
	 * recreates the rows - so comparisons go through this key instead.
	 */
	public function getKey(): string
	{
		return $this->entityType->getId() . '_' . $this->entityId . '_' . $this->type . '_' . $this->value;
	}

	public function equals(self $other): bool
	{
		return $this->getKey() === $other->getKey();
	}
}
