<?php

namespace Bitrix\Crm\Component\EntityDetails\TimelineMenuBar;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MessageSender\Channel\Correspondents\ToRepository;
use Bitrix\Crm\Multifield\Type\Phone;
use CCrmOwnerType;

final class Communications
{
	private ?ToRepository $toRepository;

	public const PHONE_TYPE = 'phone';
	public const EMAIL_TYPE = 'email';

	public static function createFromItemIdentifier(ItemIdentifier $itemIdentifier): self
	{
		return new self($itemIdentifier->getEntityTypeId(), $itemIdentifier->getEntityId());
	}

	public function __construct(private readonly int $entityTypeId, private readonly int $entityId)
	{
		$item = ItemIdentifier::createByParams($this->entityTypeId, $this->entityId);
		if ($item)
		{
			$this->toRepository = ToRepository::create($item);
		}
		else
		{
			$this->toRepository = null;
		}
	}

	public function setCheckPermissions(bool $checkPermissions): self
	{
		$this->toRepository?->setCheckPermissions($checkPermissions);

		return $this;
	}

	public function get(string $type = Phone::ID): array
	{
		if ($this->toRepository === null)
		{
			return [];
		}

		$key = $type === Phone::ID ? 'phones' : 'emails';
		$captions = [];
		$communications = [];
		foreach ($this->toRepository->getListByType($type) as $to)
		{
			$addressSource = $to->getAddressSource();
			$hash = $addressSource->getHash();
			$entityTypeId = $addressSource->getEntityTypeId();
			$entityId = $addressSource->getEntityId();

			$captions[$hash] ??= CCrmOwnerType::GetCaption(
				$entityTypeId,
				$entityId,
			);
			if (empty($captions[$hash]))
			{
				continue;
			}

			$communications[$hash] ??= [
				'entityTypeId' => $entityTypeId,
				'entityTypeName' => CCrmOwnerType::ResolveName($entityTypeId),
				'entityId' => $entityId,
				'caption' => $captions[$hash],
			];

			$address = $to->getAddress();
			$communications[$hash][$key][] = [
				'value' => $address->getValue(),
				'valueFormatted' => $address->getValueFormatted(),
				'type' => $address->getValueType(),
				'typeLabel' => $address->getValueTypeCaption(),
				'id' => $address->getId(),
			];
		}

		$notEmptyItems = array_filter(
			$communications,
			static fn(array $communication) => !empty($communication[$key]),
		);

		return array_values($notEmptyItems);
	}
}
