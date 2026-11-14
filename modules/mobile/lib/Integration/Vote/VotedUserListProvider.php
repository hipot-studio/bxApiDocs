<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Integration\Vote;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Vote\Service\VotedContextSigner;
use Bitrix\Vote\Service\VotedListService;

final class VotedUserListProvider
{
	public const PAGE_SIZE = 20;

	private const CACHE_TTL = 600;
	private const USER_URL_TEMPLATE = '/mobile/users/?user_id=#user_id#';

	/**
	 * @return array<int, array{NAME: string, ID: string, IMAGE: string, TAGS: string, WORK_POSITION: string, WORK_DEPARTMENTS: string[], URL: string}>
	 */
	public function getVoters(string $sign, int $pageNumber): array
	{
		if ($sign === '' || !Loader::includeModule('vote'))
		{
			return [];
		}

		try
		{
			$context = (new VotedContextSigner())->unsign($sign);
		}
		catch (BadSignatureException)
		{
			return [];
		}

		if ($context['voteId'] <= 0 || $context['answerId'] <= 0)
		{
			return [];
		}

		return $this->resolveCards($sign, $context['voteId'], $context['answerId'], max(1, $pageNumber));
	}

	public static function buildCacheId(int $answerId, int $pageNumber): string
	{
		return 'mobile_voted_user_list_' . serialize([
			VotedListService::VISIBILITY_RULE_VERSION,
			self::PAGE_SIZE,
			$answerId,
			$pageNumber,
		]);
	}

	/**
	 * @return array<int, array>
	 */
	private function resolveCards(string $sign, int $voteId, int $answerId, int $pageNumber): array
	{
		$cacheId = self::buildCacheId($answerId, $pageNumber);
		$cacheDir = VotedListService::buildCacheDir($voteId);

		$cache = new \CPHPCache();
		$cacheOn = Option::get('main', 'component_cache_on', 'Y') === 'Y';
		if ($cacheOn && $cache->InitCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			$cached = $cache->GetVars();
			if (is_array($cached) && isset($cached['cards']))
			{
				return $cached['cards'];
			}
		}

		$page = (new VotedListService())->getVoterIdsBySign($sign, [
			'nPageSize' => self::PAGE_SIZE,
			'iNumPage' => $pageNumber,
		]);

		$cards = $this->formatCards($page['ID']);

		if ($cacheOn && !empty($cards))
		{
			$cache->StartDataCache(self::CACHE_TTL, $cacheId, $cacheDir);
			\CVoteCacheManager::SetTag($cacheDir, 'V', $voteId);
			$cache->EndDataCache(['cards' => $cards]);
		}

		return $cards;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function formatCards(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$departments = $this->loadDepartments();

		$select = ['FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'PERSONAL_PHOTO', 'WORK_POSITION']];
		if ($departments !== null)
		{
			$select['SELECT'] = ['UF_DEPARTMENT'];
		}

		$dbRes = \CUser::GetList('ID', 'ASC', ['ID' => implode('|', $userIds)], $select);

		$cards = [];
		while ($user = $dbRes->Fetch())
		{
			$cards[(int)$user['ID']] = $this->buildCard($user, $departments);
		}

		$ordered = [];
		foreach ($userIds as $userId)
		{
			if (isset($cards[$userId]))
			{
				$ordered[] = $cards[$userId];
			}
		}

		return $ordered;
	}

	/**
	 * @param array<int, string>|null $departments
	 */
	private function buildCard(array $user, ?array $departments): array
	{
		$workPosition = trim((string)($user['WORK_POSITION'] ?? ''));
		$userDepartments = ($departments === null ? [] : $this->resolveUserDepartments($user, $departments));

		return [
			'NAME' => \CUser::FormatName(\CSite::GetNameFormat(false), $user, true, false),
			'ID' => $user['ID'],
			'IMAGE' => $this->resolvePhoto($user),
			'TAGS' => ($departments === null ? '' : implode(',', array_merge([$workPosition], $userDepartments))),
			'WORK_POSITION' => (string)($user['WORK_POSITION'] ?? ''),
			'WORK_DEPARTMENTS' => $userDepartments,
			'URL' => \CComponentEngine::MakePathFromTemplate(self::USER_URL_TEMPLATE, [
				'UID' => $user['ID'],
				'user_id' => $user['ID'],
				'USER_ID' => $user['ID'],
			]),
		];
	}

	/**
	 * @param array<int, string> $departments
	 * @return string[]
	 */
	private function resolveUserDepartments(array $user, array $departments): array
	{
		if (!is_array($user['UF_DEPARTMENT'] ?? null))
		{
			return [];
		}

		$names = [];
		foreach ($user['UF_DEPARTMENT'] as $departmentId)
		{
			if (isset($departments[(int)$departmentId]))
			{
				$names[] = $departments[(int)$departmentId];
			}
		}

		return $names;
	}

	private function resolvePhoto(array $user): string
	{
		if (empty($user['PERSONAL_PHOTO']))
		{
			return '';
		}

		$file = \CFile::ResizeImageGet(
			$user['PERSONAL_PHOTO'],
			['width' => 64, 'height' => 64],
			BX_RESIZE_IMAGE_EXACT,
			false
		);

		return (string)($file['src'] ?? '');
	}

	/**
	 * @return array<int, string>|null null when the company structure is not configured
	 */
	private function loadDepartments(): ?array
	{
		$iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
		if ($iblockId <= 0 || !Loader::includeModule('iblock'))
		{
			return null;
		}

		$dbRes = \CIBlockSection::GetList(
			['LEFT_MARGIN' => 'DESC'],
			['IBLOCK_ID' => $iblockId],
			false,
			['ID', 'NAME']
		);

		$departments = [];
		while ($section = $dbRes->Fetch())
		{
			$departments[(int)$section['ID']] = trim((string)$section['NAME']);
		}

		return $departments;
	}
}
