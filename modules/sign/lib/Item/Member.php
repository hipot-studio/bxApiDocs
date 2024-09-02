<?php

namespace Bitrix\Sign\Item;

use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\MemberStatus;
use Bitrix\Sign\Type\ProcessingStatus;

class Member implements Contract\Item
{
	public function __construct(
		public ?int $documentId = null,
		public ?int $party = null,
		public ?int $id = null,
		public ?string $uid = null,
		public string $status = MemberStatus::WAIT,
		public string $processingStatus = ProcessingStatus::WAIT,
		public ?string $name = null,
		public ?string $companyName = null,
		public ?string $channelType = null,
		public ?string $channelValue = null,
		public ?int $signedFileId = null,
		public ?DateTime $dateSigned = null,
		public ?DateTime $dateCreated = null,
		public ?string $entityType = null,
		public ?int $entityId = null,
		public ?int $presetId = null,
		public ?int $signatureFileId = null,
		public ?int $stampFileId = null,
		public ?string $role = null,
		public ?int $configured = null,
	)
	{}
}
