<?php

namespace Bitrix\Mobile\Profile\Provider;

use Bitrix\Main\Loader;
use Bitrix\Intranet\Component\UserProfile\Grats;
use Bitrix\Main\LoaderException;
use Bitrix\Main\UI\PageNavigation;

class GratitudeProvider
{
	protected ?Grats $gratitudeService;
	private const DEFAULT_PAGE_SIZE = 30;
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
		$items = $this->fetchItems($ownerId, $limit ?? self::DEFAULT_PAGE_SIZE);

		return [
			'items' => $this->collectGratitudeItems($items['POSTS'], $items['BADGES'], $ownerId),
			'totalCount' => $this->countTotalBadges($items['BADGES'])
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
		$pageSize = $pageNavigation?->getPageSize() ?? self::DEFAULT_PAGE_SIZE;
		$pageNum = $pageNavigation?->getCurrentPage();

		$items = $this->fetchItems($ownerId, $pageSize, $pageNum);
		$gratitudeItems = $this->collectGratitudeItems($items['POSTS'], $items['BADGES'], $ownerId);

		return [
			'items' => $gratitudeItems,
			'authorIds' => $this->extractAuthorIds($items['POSTS']),
		];
	}

	/**
	 * @throws LoaderException
	 */
	private function fetchItems(int $ownerId, int $pageSize, ?int $pageNum = 1): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		$service = $this->getGratitudeService($ownerId, $pageSize);
		$result = $service->getGratitudePostListAction(['pageNum' => $pageNum]);
		$stub = $service->getStub();
		$badges = $this->formatBadges($result['BADGES'] ?? [], $stub['BADGES'] ?? []);

		return [
			'POSTS' => $result['POSTS'] ?? [],
			'BADGES' => $badges ?? [],
		];
	}

	private function formatBadges(array $badges, array $stubBadges): ?array
	{
		$nameToCode = [];
		foreach ($stubBadges as $stub)
		{
			if (!is_array($stub))
			{
				continue;
			}
			$name = $stub['NAME'] ?? '';
			if ($name === '')
			{
				continue;
			}
			$nameToCode[$name] = $stub['CODE'] ?? '';
		}

		foreach ($badges as $key => $badge)
		{
			$name = is_array($badge) ? ($badge['NAME'] ?? '') : '';
			$badges[$key]['CODE'] = $nameToCode[$name] ?? '';
		}

		return $badges;
	}

	private function getGratitudeService(int $ownerId, ?int $pageSize = self::DEFAULT_PAGE_SIZE): Grats
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

	private function countTotalBadges(array $badges): int
	{
		return array_reduce(
			$badges,
			static fn(int $total, array $badge) => $total + count($badge['ID'] ?? []),
			0
		);
	}

	private function collectGratitudeItems(array $posts, array $badges, int $ownerId): array
	{
		$maps = $this->createBadgeMaps($badges);
		$nameMap = $maps['name'];
		$codeMap = $maps['code'];
		$result = [];

		foreach ($posts as $post)
		{
			$badgeId = (string)($post['UF_GRATITUDE'] ?? '');

			$result[] = [
				'id' => (int)$badgeId,
				'name' => (string)($nameMap[$badgeId] ?? ''),
				'feedId' => (string)($codeMap[$badgeId] ?? ''),
				'title' => (string)$post['TITLE'],
				'authorId' => (int)$post['AUTHOR_ID'],
				'createdAt' => (int)$post['DATE_PUBLISH_TS'],
				'relatedPostId' => (int)$post['ID'],
				'ownerId' => $ownerId,
			];
		}

		if (!empty($result))
		{
			usort($result, static fn($newer, $older) => $older['createdAt'] <=> $newer['createdAt']);
		}

		return $result;
	}

	private function createBadgeMaps(array $badges): array
	{
		$nameMap = [];
		$codeMap = [];

		foreach ($badges as $badge)
		{
			if (!is_array($badge))
			{
				continue;
			}

			$name = $badge['NAME'] ?? '';
			$code = $badge['CODE'] ?? '';

			foreach ($badge['ID'] ?? [] as $id)
			{
				$key = (string)$id;
				$nameMap[$key] = $name;
				$codeMap[$key] = $code;
			}
		}

		return [
			'name' => $nameMap,
			'code' => $codeMap,
		];
	}

	private function extractAuthorIds(array $posts): array
	{
		return array_values(array_unique(array_filter(
			array_column($posts, 'AUTHOR_ID'),
			static fn($id) => !empty($id)
		)));
	}
}
