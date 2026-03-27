<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

class PartnerInfo implements EntityInterface, Arrayable
{
	public function __construct(
		public readonly int $userId,
		public readonly int $integratorId,
		public readonly string $integratorName,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	public static function createByOption(array $option): self
	{
		if (!isset($option['userId'], $option['integratorId'], $option['integratorName']))
		{
			throw new ArgumentException();
		}

		return new self(
			userId: $option['userId'],
			integratorId: $option['integratorId'],
			integratorName:  $option['integratorName'],
		);
	}

	/**
	 * @throws ArgumentException
	 */
	public static function createByResponseAndUserId(array $response, int $userId): self
	{
		if (!isset($response['partnerName'], $response['partnerId']) || $userId < 1)
		{
			throw new ArgumentException();
		}

		return new self(
			userId: $userId,
			integratorId: (int)$response['partnerId'],
			integratorName: $response['partnerName'],
		);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'integratorId' => $this->integratorId,
			'integratorName' => $this->integratorName,
		];
	}

	public function getId(): int
	{
		return $this->userId;
	}
}
