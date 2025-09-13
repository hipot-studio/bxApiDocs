<?php

namespace Bitrix\Mobile\Profile\Provider;

use Bitrix\Main\Loader;
use Bitrix\Intranet\Component\UserProfile\Grats;
use Bitrix\Main\LoaderException;
use Bitrix\Main\UI\PageNavigation;

class GratitudeProvider
{
	protected ?Grats $gratitudeService;

	/**
	 * @param Grats|null $gratitudeService
	 */
	public function __construct(?Grats $gratitudeService = null)
	{
		$this->gratitudeService = $gratitudeService;
	}

	/**
	 * @param int $ownerId
	 * @param int|null $limit
	 * @return array
	 * @throws LoaderException
	 */
	public function getBadges(int $ownerId, ?int $limit = null): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		$service = $this->getGratitudeService($ownerId);
		$badges = $service->getGratitudePostListAction()['BADGES'] ?? [];

		return [
			'items' => $this->collectBadgeItems($badges, $ownerId, $limit),
			'totalCount' => $this->countTotalBadges($badges)
		];
	}

	/**
	 * @param int $ownerId
	 * @param PageNavigation|null $pageNavigation
	 * @return array
	 * @throws LoaderException
	 */
	public function getListItems(int $ownerId, ?PageNavigation $pageNavigation = null): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		$service = $this->getGratitudeService($ownerId, $pageNavigation?->getPageSize());
		$items = $service->getGratitudePostListAction([
			'pageNum' => $pageNavigation?->getCurrentPage() ?: 0
		]);

		return [
			'items' => $this->collectPostItems($items['POSTS'] ?? [], $items['BADGES'] ?? [], $ownerId),
			'authorIds' => $this->extractAuthorIds($items['POSTS'] ?? [])
		];
	}

	private function getGratitudeService(int $ownerId, int $pageSize = 20): Grats
	{
		if (!$this->gratitudeService)
		{
			$this->gratitudeService = new Grats([
				'profileId' => $ownerId,
				'pageSize' => $pageSize,
				'pathToPostEdit' => '',
				'pathToUserGrat' => '',
				'pathToPost' => '',
				'pathToUser' => '',
			]);
		}

		return $this->gratitudeService;
	}

	private function collectBadgeItems(array $badges, int $ownerId, ?int $limit): array
	{
		$result = [];
		foreach ($badges as $badge)
		{
			foreach ($badge['ID'] as $id)
			{
				$result[] = [
					'id' => $id,
					'name' => $badge['NAME'],
					'ownerId' => $ownerId
				];

				if ($limit && count($result) === $limit)
				{
					return $result;
				}
			}
		}
		return $result;
	}

	private function countTotalBadges(array $badges): int
	{
		return array_reduce(
			$badges,
			static fn(int $total, array $badge) => $total + count($badge['ID'] ?? []),
			0
		);
	}

	private function collectPostItems(array $posts, array $badges, int $ownerId): array
	{
		$badgeNames = $this->createBadgeNameMap($badges);

		foreach ($posts as $post)
		{
			$result[] = [
				'id' => (int)$post['UF_GRATITUDE'],
				'name' => (string)$badgeNames[$post['UF_GRATITUDE'] ?? ''],
				'title' => (string)$post['TITLE'],
				'authorId' => (int)$post['AUTHOR_ID'],
				'createdAt' => (int)$post['DATE_PUBLISH_TS'],
				'formattedAt' => strtotime($post['DATE_FORMATTED'] ?? ''),
				'relatedPostId' => (int)$post['ID'],
				'ownerId' => $ownerId,
			];
		}

		return $result;
	}

	private function createBadgeNameMap(array $badges): array
	{
		$map = [];
		foreach ($badges as $badge)
		{
			foreach ($badge['ID'] ?? [] as $id)
			{
				$map[$id] = $badge['NAME'] ?? '';
			}
		}

		return $map;
	}

	private function extractAuthorIds(array $posts): array
	{
		return array_values(array_unique(array_filter(
			array_column($posts, 'AUTHOR_ID'),
			static fn($id) => !empty($id)
		)));
	}
}
