<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Crm\MessageSender\UI\Editor\Preferences\ChannelPosition;
use Bitrix\Crm\MessageSender\UI\Editor\Preferences\ChannelLastUsedFrom;

final class Preferences extends Dto
{
	/** @var array<ChannelPosition>|null */
	public ?array $channelsSort = null;
	/** @var array<ChannelPosition>|null */
	public ?array $channelsLastUsedFrom = null;

	protected function getValidators(array $fields): array
	{
		return [
			new ObjectCollectionField($this, 'channelsSort'),
			new ObjectCollectionField($this, 'channelsLastUsedFrom'),
		];
	}

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'channelsSort' => new Caster\CollectionCaster(new Caster\ObjectCaster(ChannelPosition::class)),
			'channelsLastUsedFrom' => new Caster\CollectionCaster(new Caster\ObjectCaster(ChannelLastUsedFrom::class)),
			default => null,
		};
	}
}
