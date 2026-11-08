<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Dto;

use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Launch\LaunchAppTarget;

final class LaunchAppTargetDto
{
	public function __construct(
		public readonly string $title,
		public readonly ?string $viewUrl,
		public readonly ?string $externalId,
		public readonly int $ownerId,
		public readonly ?string $ownerName,
		public readonly bool $isMine,
	) {}

	public static function fromLaunchTarget(LaunchAppTarget $target): self
	{
		return new self(
			title: $target->title,
			viewUrl: $target->viewUrl,
			externalId: $target->externalId,
			ownerId: $target->ownerId,
			ownerName: $target->ownerName,
			isMine: $target->isMine,
		);
	}

	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'viewUrl' => $this->viewUrl,
			'externalId' => $this->externalId,
			'ownerId' => $this->ownerId,
			'ownerName' => $this->ownerName,
			'isMine' => $this->isMine,
		];
	}
}
