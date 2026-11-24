<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Promotion\Service;

use Bitrix\Im\Common;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Common\SingletonTrait;
use Bitrix\Im\V2\Folder\Definition\CompositeDefinition;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\System\AllFolder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;
use Bitrix\Im\V2\Folder\System\TaskFolder;
use Bitrix\Im\V2\Folder\System\TaskRootFolder;
use Bitrix\Im\V2\Promotion\Entity\Promotion;
use Bitrix\Im\V2\Promotion\Entity\PromotionList;
use Bitrix\Im\V2\Promotion\Event\Read\PromotionReadEvent;
use Bitrix\Im\V2\Promotion\Internals\DeviceType;
use Bitrix\Im\V2\Promotion\Internals\PromotionType;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\UI\Tour;

/**
 * One-time banner of the web messenger offering to take the task chats out of the folder "All".
 */
class FolderAllSettingsBannerPromotionService implements PromotionServiceInterface
{
	use SingletonTrait;

	public const PROMO_ID = 'im:folder-all-settings-banner:19102026:all';

	private const TASK_CHAT_CACHE_TTL = 86400;
	private const TASK_CHAT_CACHE_DIR = '/im/promotion/folder_all_settings_banner/';

	public function getActive(DeviceType $type = DeviceType::ALL): PromotionList
	{
		$promotions = new PromotionList();

		if (!$type->isDeviceTypeAvailable(DeviceType::WEB->value))
		{
			return $promotions;
		}

		$userId = (int)Common::getUserId();
		if ($userId <= 0)
		{
			return $promotions;
		}

		if ($this->isAvailableForUser($userId))
		{
			$promotions->add(new Promotion(self::PROMO_ID));
		}

		return $promotions;
	}

	public function getPromotionType(): PromotionType
	{
		return PromotionType::UI;
	}

	public function isCurrentTypePromotion(string $promotionId): bool
	{
		return $promotionId === self::PROMO_ID;
	}

	public function markAsViewed(Promotion $promotion): Result
	{
		$result = new Result();

		$userId = (int)Common::getUserId();
		$this->createTour()->setViewDate($userId);

		(new PromotionReadEvent($userId, self::PROMO_ID))->send();

		return $result;
	}

	/**
	 * The checks go from the cheapest to the most expensive: a user the banner is over for costs
	 * no read of the recent list or of the folders.
	 */
	private function isAvailableForUser(int $userId): bool
	{
		if (!Features::isChatFolderAllAvailable())
		{
			return false;
		}

		$tour = $this->createTour();
		if (!$tour->isAvailable($userId))
		{
			return false;
		}

		if (!Features::isChatFoldersWebAvailableForUser($userId) || !$this->isAllFolderAvailable($userId))
		{
			return false;
		}

		if (!Features::isTasksRecentListAvailable())
		{
			return false;
		}

		if ($this->isFolderAllOnboardingAhead($userId))
		{
			return false;
		}

		if (!$this->hasAnyTaskChat($userId))
		{
			return false;
		}

		// Reading the folders creates the system ones on a cache miss, so it stays behind the check of "All".
		$foldersByCode = $this->getSystemFoldersByCode($userId);
		$allFolder = $foldersByCode[AllFolder::CODE] ?? null;
		$tasksFolder = $foldersByCode[TaskFolder::CODE] ?? null;
		if ($allFolder === null || $tasksFolder === null)
		{
			return false;
		}

		$sourceCodes = $allFolder->getDefinition()->toRestFormat()['sourceFolders'] ?? [];
		$isTaskSourceEnabled = in_array(TaskRootFolder::CODE, $sourceCodes, true);
		if (!$isTaskSourceEnabled || $tasksFolder->isHiddenByUser())
		{
			$tour->setViewDate($userId);

			return false;
		}

		return true;
	}

	private function createTour(): Tour
	{
		$tour = new Tour(self::PROMO_ID);
		$tour->setUserType(Tour::USER_TYPE_OLD);
		$tour->setUserTimeSpan(0);
		$tour->setLifetime(0);

		return $tour;
	}

	private function isAllFolderAvailable(int $userId): bool
	{
		$allFolder = (new SystemFolderRegistry())->findByCode(AllFolder::CODE)?->instantiate();

		return $allFolder !== null && $allFolder->isAvailable($userId);
	}

	private function isFolderAllOnboardingAhead(int $userId): bool
	{
		$onboardingTour = new Tour(UIPromotionService::FOLDER_ALL_ONBOARDING_PROMO_ID);

		return
			!$onboardingTour->isViewed($userId)
			&& UIPromotionService::getInstance()->isFolderAllOnboardingPromoAllowedByPortalAge();
	}

	/**
	 * The promo list is collected on every page of the portal, so the answer is kept for a day either
	 * way: a user without task chats would otherwise read the recent list on each of them.
	 */
	private function hasAnyTaskChat(int $userId): bool
	{
		$selection = (new CompositeDefinition([TaskRootFolder::CODE], $userId))->toSelection();
		if ($selection->isEmpty())
		{
			return false;
		}

		$cacheId = 'has_task_chat_' . $userId;
		$cacheDir = $this->getTaskChatCacheDir($userId);

		$cache = Cache::createInstance();
		if ($cache->initCache(self::TASK_CHAT_CACHE_TTL, $cacheId, $cacheDir))
		{
			// Wrapped in an array, so that a stored "no" is told apart from a missing entry.
			$vars = $cache->getVars();
			if (is_array($vars) && is_bool($vars[0] ?? null))
			{
				return $vars[0];
			}
		}

		$recent = ServiceLocator::getInstance()->get(RecentProvider::class)->getList(
			new RecentParams(
				new RecentFilter(userId: $userId, selection: $selection),
				limit: 1,
			),
		);
		$hasAnyTaskChat = $recent->count() > 0;

		$cache = Cache::createInstance();
		// An unreadable entry is still on disk and would block the write otherwise.
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::TASK_CHAT_CACHE_TTL, $cacheId, $cacheDir))
		{
			$cache->endDataCache([$hasAnyTaskChat]);
		}

		return $hasAnyTaskChat;
	}

	private function getTaskChatCacheDir(int $userId): string
	{
		return self::TASK_CHAT_CACHE_DIR . substr(md5((string)$userId), 2, 2) . '/' . $userId . '/';
	}

	/**
	 * @return array<string, SystemFolder>
	 */
	private function getSystemFoldersByCode(int $userId): array
	{
		$foldersByCode = [];

		foreach (ServiceLocator::getInstance()->get(FolderProvider::class)->getByUser($userId) as $folder)
		{
			$code = $folder instanceof SystemFolder ? $folder->getCode() : null;
			if ($code !== null)
			{
				$foldersByCode[$code] = $folder;
			}
		}

		return $foldersByCode;
	}
}
