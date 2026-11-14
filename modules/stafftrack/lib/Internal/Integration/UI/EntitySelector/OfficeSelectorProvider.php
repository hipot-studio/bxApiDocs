<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\UI\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Internal\Integration\Intranet\UserService;
use Bitrix\StaffTrack\Internal\Model\Office;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\OfficesAvailability;
use Bitrix\StaffTrack\Internal\Service\Office\PhotoService;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

class OfficeSelectorProvider extends BaseProvider
{
	public const ENTITY_ID = 'stafftrack-office';
	public const INITIAL_LIMIT = 100;
	public const SEARCH_LIMIT = 100;

	private const TAB_ID = 'recents';
	private const PLACEHOLDER_AVATAR_OPTIONS = [
		'icon' => 'o-company',
		'iconColor' => 'var(--ui-color-accent-main-primary)',
		'bgColor' => 'var(--ui-color-accent-soft-blue-3)',
	];

	/**
	 * @param array{excludedIds?: mixed} $options The options of the entity as the client sent them.
	 */
	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options['excludedIds'] = $this->normalizeIds($options['excludedIds'] ?? []);
	}

	public function isAvailable(): bool
	{
		$userId = $this->getCurrentUserId();
		if ($userId <= 0)
		{
			return false;
		}

		return $this->getOfficesAvailability()->isAvailable()
			&& $this->isIntranetUser($userId)
		;
	}

	public function getItems(array $ids): array
	{
		return $this->makeItems($this->getOfficeRepository()->getByIds($ids));
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->loadPreselectedItems();

		foreach ($dialog->getPreselectedCollection()->getEntityItems(self::ENTITY_ID) as $preselectedItem)
		{
			$item = $preselectedItem->getItem();
			if ($item)
			{
				$dialog->addRecentItem($item);
			}
		}

		$offices = $this->getOfficeRepository()->getListLimited(self::INITIAL_LIMIT);
		foreach ($this->makeItems($offices) as $item)
		{
			$dialog->addRecentItem($item);
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);

		$offices = $this->getOfficeRepository()->findByTitleSubstring($searchQuery->getQuery(), self::SEARCH_LIMIT);

		$dialog->addItems($this->makeItems($offices));
	}

	protected function getOfficeRepository(): OfficeRepository
	{
		return new OfficeRepository();
	}

	protected function getPhotoService(): PhotoService
	{
		return new PhotoService();
	}

	protected function getOfficesAvailability(): OfficesAvailability
	{
		return new OfficesAvailability();
	}

	protected function isIntranetUser(int $userId): bool
	{
		return UserService::isIntranetUser($userId);
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/**
	 * An excluded office is dropped here, where the recent items, the search and the preselected items
	 * all pass, and before its photo is asked for.
	 *
	 * @param Office[] $offices
	 * @return Item[]
	 */
	private function makeItems(array $offices): array
	{
		$excludedIds = array_flip($this->getOption('excludedIds', []));
		$offices = array_values(array_filter(
			$offices,
			static fn (Office $office): bool => !isset($excludedIds[(int)$office->getId()]),
		));

		if ($offices === [])
		{
			return [];
		}

		$officeIds = array_map(static fn (Office $office): int => (int)$office->getId(), $offices);
		$avatarUrls = $this->getPhotoService()->getAvatarUrlsByOfficeIds($officeIds);

		return array_map(
			fn (Office $office): Item => $this->makeItem($office, $avatarUrls[(int)$office->getId()] ?? null),
			$offices,
		);
	}

	/**
	 * @return int[] Unique positive ids; whatever is not a whole number is dropped and not cast to one.
	 */
	private function normalizeIds(mixed $ids): array
	{
		if (!is_array($ids))
		{
			return [];
		}

		$normalized = [];
		foreach ($ids as $id)
		{
			$id = is_int($id) || is_string($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;
			if ($id !== false && $id > 0)
			{
				$normalized[$id] = $id;
			}
		}

		return array_values($normalized);
	}

	private function makeItem(Office $office, ?string $avatarUrl): Item
	{
		return new Item([
			'id' => $office->getId(),
			'entityId' => self::ENTITY_ID,
			'title' => $office->getTitle(),
			'subtitle' => (string)$office->getAddress(),
			'avatar' => $avatarUrl,
			'avatarOptions' => $avatarUrl === null ? self::PLACEHOLDER_AVATAR_OPTIONS : [],
			'tabs' => self::TAB_ID,
		]);
	}
}
