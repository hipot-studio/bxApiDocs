<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Ui\EntitySelector;

use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;

/**
 * Offers the two Disk access codes that stand for no real entity: all authorized users and the object
 * author. Item id is the access code itself, so no mapping is needed on the client.
 */
final class SpecialAccessProvider extends BaseProvider
{
	public const ENTITY_ID = 'disk-special-access';

	private const TAB_ID = 'disk-special-access';

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = $options;
	}

	public function isAvailable(): bool
	{
		return (int)CurrentUser::get()->getId() > 0;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('DISK_SPECIAL_ACCESS_PROVIDER_TAB_TITLE'),
		]));

		$dialog->addItems($this->makeItems());
	}

	public function getItems(array $ids): array
	{
		$requestedIds = array_flip(array_map('strval', $ids));

		return array_values(
			array_filter(
				$this->makeItems(),
				static fn(Item $item): bool => isset($requestedIds[$item->getId()]),
			)
		);
	}

	public function getPreselectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	/**
	 * @return Item[]
	 */
	private function makeItems(): array
	{
		return [
			$this->makeItem(
				AccessCodeEnum::AUTHORIZED_USER,
				Loc::getMessage('DISK_SPECIAL_ACCESS_PROVIDER_ITEM_AUTHORIZED_USER'),
			),
			$this->makeItem(
				AccessCodeEnum::CREATOR,
				Loc::getMessage('DISK_SPECIAL_ACCESS_PROVIDER_ITEM_CREATOR'),
			),
		];
	}

	private function makeItem(AccessCodeEnum $accessCode, ?string $title): Item
	{
		return new Item([
			'id' => $accessCode->value,
			'entityId' => self::ENTITY_ID,
			'title' => $title,
			'tabs' => [self::TAB_ID],
		]);
	}
}
