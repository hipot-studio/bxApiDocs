<?php

namespace Bitrix\Sign\Item\Document;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Sign\Type\Document\ExternalIdSourceType;

/**
 * Snapshot of the document fields, of the member composition and of the company the smart process
 * card points at, that can be applied back.
 *
 * @see \Bitrix\Sign\Service\Sign\DocumentService::captureState()
 * @see \Bitrix\Sign\Operation\Document\RestoreState
 */
final class State implements Contract\Item
{
	public function __construct(
		public readonly string $documentUid,
		public readonly ?int $representativeId = null,
		public readonly ?string $companyUid = null,
		public readonly ?int $companyEntityId = null,
		// null when the card was not read: nothing is known about its company, so nothing is put back
		public readonly ?int $myCompanyId = null,
		public readonly ?string $providerCode = null,
		public readonly ?string $regionDocumentType = null,
		public readonly ?string $externalId = null,
		public readonly ?ExternalIdSourceType $externalIdSourceType = null,
		public readonly ?int $hcmLinkExternalIdSettingId = null,
		public readonly ?int $parties = null,
		public readonly Item\MemberCollection $members = new Item\MemberCollection(),
	)
	{
	}

	public static function capture(
		Item\Document $document,
		Item\MemberCollection $members,
		?int $myCompanyId = null,
	): self
	{
		return new self(
			documentUid: (string)$document->uid,
			representativeId: $document->representativeId,
			companyUid: $document->companyUid,
			companyEntityId: $document->companyEntityId,
			myCompanyId: $myCompanyId,
			providerCode: $document->providerCode,
			regionDocumentType: $document->regionDocumentType,
			externalId: $document->externalId,
			externalIdSourceType: $document->externalIdSourceType,
			hcmLinkExternalIdSettingId: $document->hcmLinkExternalIdSettingId,
			parties: $document->parties,
			// the snapshot keeps the composition the way it is applied back, as a new one
			members: $members->copyForWrite(),
		);
	}
}
