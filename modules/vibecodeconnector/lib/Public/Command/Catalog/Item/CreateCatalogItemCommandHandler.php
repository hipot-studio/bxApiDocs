<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item;

use Bitrix\Main\ArgumentException;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon\IconFileResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Writer\EntryWriter;

final class CreateCatalogItemCommandHandler
{
	public function __construct(
		private readonly EntryWriter $writer = new EntryWriter(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly IconFileResolver $iconFileResolver = new IconFileResolver(),
	)
	{
	}

	public function __invoke(CreateCatalogItemCommand $command): CatalogItem
	{
		$type = CatalogItemType::tryFrom($command->type);
		if ($type === null)
		{
			throw new ArgumentException('Unsupported type value', 'type');
		}

		$accessType = CatalogItemAccessType::tryFrom($command->accessType);
		if ($accessType === null)
		{
			throw new ArgumentException('Unsupported accessType value', 'accessType');
		}

		$viewUrl = $this->normalizeNullableString($command->viewUrl);
		$this->assertViewUrlIsPresentForApplication($type, $viewUrl);

		$pairingIss = $this->resolvePairingIss($command->iss);

		$iconFileId = $this->iconFileResolver->resolve($command->iconUrl, $pairingIss);

		return $this->writer->create(
			title: trim($command->title),
			type: $type,
			accessType: $accessType,
			ownerId: $command->userId,
			description: $this->normalizeNullableString($command->description),
			editUrl: $this->normalizeNullableString($command->editUrl),
			viewUrl: $viewUrl,
			chatId: $command->chatId,
			externalId: $this->normalizeNullableString($command->externalId),
			pairingIss: $pairingIss,
			iconFileId: $iconFileId,
		);
	}

	private function resolvePairingIss(?string $iss): ?string
	{
		$iss = $this->normalizeNullableString($iss);
		if ($iss === null)
		{
			return null;
		}

		if ($this->pairingRepository->findByIss($iss) === null)
		{
			throw new ArgumentException('Unknown pairing iss', 'iss');
		}

		return $iss;
	}

	private function assertViewUrlIsPresentForApplication(CatalogItemType $type, ?string $viewUrl): void
	{
		if ($type === CatalogItemType::Application && $viewUrl === null)
		{
			throw new ArgumentException('viewUrl is required for application items', 'viewUrl');
		}
	}

	private function normalizeNullableString(?string $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		$value = trim($value);

		return $value === '' ? null : $value;
	}
}
