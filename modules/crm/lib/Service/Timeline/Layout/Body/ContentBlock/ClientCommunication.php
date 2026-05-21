<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

class ClientCommunication extends ContentBlock
{
	private const COMMUNICATION_TYPE_PHONE = 'PHONE';
	private const COMMUNICATION_TYPE_EMAIL = 'EMAIL';
	private const COMMUNICATION_TYPE_IM = 'IM';

	private array $multiFields;
	private int $ownerTypeId;
	private int $ownerId;
	private int $entityTypeId;
	private int $entityId;

	public function __construct(array $multiFields, int $ownerTypeId, int $ownerId, int $entityTypeId, int $entityId)
	{
		$this->multiFields = $multiFields;
		$this->ownerTypeId = $ownerTypeId;
		$this->ownerId = $ownerId;
		$this->entityTypeId = $entityTypeId;
		$this->entityId = $entityId;
	}

	public function getRendererName(): string
	{
		return 'ClientCommunication';
	}

	protected function getProperties(): array
	{
		return [
			'communications' => $this->prepareCommunications(),
			'ownerTypeId' => $this->ownerTypeId,
			'ownerId' => $this->ownerId,
			'entityTypeId' => $this->entityTypeId,
			'entityId' => $this->entityId,
		];
	}

	private function prepareCommunications(): array
	{
		$grouped = array_fill_keys(
			[
				self::COMMUNICATION_TYPE_PHONE,
				self::COMMUNICATION_TYPE_EMAIL,
				self::COMMUNICATION_TYPE_IM
			],
			[]
		);

		foreach ($this->multiFields as $field)
		{
			$typeId = $field['TYPE_ID'] ?? '';
			if (isset($grouped[$typeId]))
			{
				$grouped[$typeId][] = [
					'id' => $field['ID'] ?? null,
					'value' => $field['VALUE'] ?? '',
					'valueFormatted' => $field['VALUE_FORMATTED'] ?? '',
					'complexName' => $field['COMPLEX_NAME'] ?? '',
					'title' => $field['TITLE'] ?? '',
				];
			}
		}

		return $grouped;
	}
}
