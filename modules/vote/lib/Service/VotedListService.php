<?php

namespace Bitrix\Vote\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Vote\Vote\Anonymity;

class VotedListService
{
	public const DEFAULT_PAGE_SIZE = 10;
	public const VISIBILITY_RULE_VERSION = 2;
	private const CACHE_TTL = 600;
	private const DEFAULT_URL_TEMPLATE = '/company/personal/user/#USER_ID#/';

	private VotedContextSigner $signer;

	public function __construct(?VotedContextSigner $signer = null)
	{
		$this->signer = $signer ?? new VotedContextSigner();
	}

	/**
	 * @param array{iNumPage?: int|string, nPageSize?: int, requestId?: string} $params
	 * @return array{ID: int[], answer_id: int, request_id: string, items: array, StatusPage: string}
	 */
	public function getListBySign(?string $sign, array $params): array
	{
		$requestId = (string)($params['requestId'] ?? '');

		$request = $this->resolveRequest($sign, $params);
		if ($request === null)
		{
			return $this->emptyResult($requestId);
		}

		$page = $this->resolvePage(
			$request['voteId'],
			$request['answerId'],
			$request['nPageSize'],
			$request['iNumPage']
		);

		$items = $this->formatCards(
			$page['users'],
			($request['nameTemplate'] === '' ? \CSite::getNameFormat(false) : $request['nameTemplate']),
			$this->resolveUrlTemplate($request['urlTemplate'])
		);

		return [
			'ID' => array_map(static fn(array $user): int => (int)$user['ID'], $page['users']),
			'answer_id' => $request['answerId'],
			'request_id' => $requestId,
			'items' => $items,
			'StatusPage' => $page['statusPage'],
		];
	}

	/**
	 * @param array{iNumPage?: int|string, nPageSize?: int} $params
	 * @return array{ID: int[], answer_id: int, StatusPage: string}
	 */
	public function getVoterIdsBySign(?string $sign, array $params): array
	{
		$request = $this->resolveRequest($sign, $params);
		if ($request === null)
		{
			return ['ID' => [], 'answer_id' => 0, 'StatusPage' => 'done'];
		}

		$page = $this->fetchIdPage(
			$request['answerId'],
			$request['nPageSize'],
			$request['iNumPage']
		);

		return [
			'ID' => $page['ids'],
			'answer_id' => $request['answerId'],
			'StatusPage' => $page['statusPage'],
		];
	}

	public static function buildCacheId(int $answerId, int $nPageSize, int $iNumPage): string
	{
		return 'vote_user_list_' . serialize([self::VISIBILITY_RULE_VERSION, $nPageSize, $answerId, $iNumPage]);
	}

	public static function buildCacheDir(int $voteId): string
	{
		return '/vote/' . $voteId . '/voted/';
	}

	/**
	 * @param array{iNumPage?: int|string, nPageSize?: int} $params
	 * @return array{voteId: int, answerId: int, nameTemplate: string, urlTemplate: string, nPageSize: int, iNumPage: int}|null
	 */
	private function resolveRequest(?string $sign, array $params): ?array
	{
		if ($sign === null || $sign === '')
		{
			return null;
		}

		try
		{
			$context = $this->signer->unsign($sign);
		}
		catch (BadSignatureException)
		{
			return null;
		}

		if ($context['voteId'] <= 0 || $context['answerId'] <= 0)
		{
			return null;
		}

		$nPageSize = (int)($params['nPageSize'] ?? self::DEFAULT_PAGE_SIZE);
		if ($nPageSize <= 0)
		{
			$nPageSize = self::DEFAULT_PAGE_SIZE;
		}

		return [
			'voteId' => $context['voteId'],
			'answerId' => $context['answerId'],
			'nameTemplate' => $context['nameTemplate'],
			'urlTemplate' => $context['urlTemplate'],
			'nPageSize' => $nPageSize,
			'iNumPage' => max(1, (int)($params['iNumPage'] ?? 1)),
		];
	}

	/**
	 * @return array{statusPage: string, users: array<int, array>}
	 */
	private function resolvePage(int $voteId, int $answerId, int $nPageSize, int $iNumPage): array
	{
		$cacheId = self::buildCacheId($answerId, $nPageSize, $iNumPage);
		$cacheDir = self::buildCacheDir($voteId);

		$cache = new \CPHPCache();
		$cacheOn = \Bitrix\Main\Config\Option::get('main', 'component_cache_on', 'Y') === 'Y';
		if ($cacheOn && $cache->InitCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			$cached = $cache->GetVars();
			if (is_array($cached) && isset($cached['users']))
			{
				return $cached;
			}
		}

		$page = $this->fetchPage($answerId, $nPageSize, $iNumPage);

		if ($cacheOn && !empty($page['users']))
		{
			$cache->StartDataCache(self::CACHE_TTL, $cacheId, $cacheDir);
			\CVoteCacheManager::SetTag($cacheDir, 'V', $voteId);
			$cache->EndDataCache($page);
		}

		return $page;
	}

	/**
	 * @return array{statusPage: string, users: array<int, array>}
	 */
	private function fetchPage(int $answerId, int $nPageSize, int $iNumPage): array
	{
		$page = $this->fetchIdPage($answerId, $nPageSize, $iNumPage);

		if (empty($page['ids']))
		{
			return ['statusPage' => $page['statusPage'], 'users' => []];
		}

		$profiles = $this->loadProfiles($page['ids']);

		$users = [];
		foreach ($page['ids'] as $id)
		{
			if (isset($profiles[$id]))
			{
				$users[] = $profiles[$id];
			}
		}

		return ['statusPage' => $page['statusPage'], 'users' => $users];
	}

	/**
	 * @return array{statusPage: string, ids: int[]}
	 */
	private function fetchIdPage(int $answerId, int $nPageSize, int $iNumPage): array
	{
		$result = ['statusPage' => 'done', 'ids' => []];

		if (!Loader::includeModule('vote'))
		{
			return $result;
		}

		$dbRes = \CVoteEvent::GetUserAnswerStat(
			[],
			['ANSWER_ID' => $answerId, 'VALID' => 'Y', 'VISIBLE' => 'Y', 'bGetVoters' => 'Y', 'bGetMemoStat' => 'N'],
			['nPageSize' => $nPageSize, 'bShowAll' => false, 'iNumPage' => $iNumPage]
		);

		if (!$dbRes || !($row = $dbRes->Fetch()))
		{
			return $result;
		}

		// A vote turned anonymous after voting keeps VISIBLE = 'Y' on its early events.
		if (!$this->isVoterListOpen((int)$row['QUESTION_ID']))
		{
			return $result;
		}

		if ($iNumPage > $dbRes->NavPageCount)
		{
			return $result;
		}

		$lastPageReached = $dbRes->NavPageNomer >= $dbRes->NavPageCount || $nPageSize > $dbRes->NavRecordCount;
		$result['statusPage'] = ($lastPageReached ? 'done' : 'continue');

		$userIds = [];
		do
		{
			$userIds[] = (int)$row['AUTH_USER_ID'];
		}
		while ($row = $dbRes->Fetch());

		$result['ids'] = array_values(array_unique(array_filter($userIds)));
		if (empty($result['ids']))
		{
			$result['statusPage'] = 'done';
		}

		return $result;
	}

	private function isVoterListOpen(int $questionId): bool
	{
		$questionRes = \CVoteQuestion::GetByID($questionId);
		$question = ($questionRes ? $questionRes->Fetch() : false);
		$voteRes = ($question ? \CVote::GetByIDEx($question['VOTE_ID']) : false);
		$vote = ($voteRes ? $voteRes->Fetch() : false);

		return $vote && Anonymity::isUserVoteVisible(true, (int)$vote['ANONYMITY']);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function loadProfiles(array $userIds): array
	{
		$select = ['FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'PERSONAL_PHOTO']];
		$mailInstalled = IsModuleInstalled('mail');
		$extranetInstalled = IsModuleInstalled('extranet');
		if ($mailInstalled)
		{
			$select['FIELDS'][] = 'EXTERNAL_AUTH_ID';
		}
		if ($extranetInstalled)
		{
			$select['SELECT'] = ['UF_DEPARTMENT'];
		}

		$dbRes = \CUser::GetList('ID', 'ASC', ['ID' => implode('|', $userIds)], $select);

		$profiles = [];
		while ($res = $dbRes->Fetch())
		{
			$data = [
				'ID' => (int)$res['ID'],
				'NAME' => $res['NAME'] ?? '',
				'LAST_NAME' => $res['LAST_NAME'] ?? '',
				'SECOND_NAME' => $res['SECOND_NAME'] ?? '',
				'LOGIN' => $res['LOGIN'] ?? '',
				'PHOTO' => '',
				'PHOTO_SRC' => '',
				'TYPE' => '',
			];

			if (array_key_exists('PERSONAL_PHOTO', $res) && !empty($res['PERSONAL_PHOTO']))
			{
				$file = \CFile::ResizeImageGet(
					$res['PERSONAL_PHOTO'],
					['width' => 21, 'height' => 21],
					BX_RESIZE_IMAGE_EXACT,
					false
				);
				$data['PHOTO'] = \CFile::ShowImage($file['src'], 21, 21, 'border=0');
				$data['PHOTO_SRC'] = (string)$file['src'];
			}

			if ($mailInstalled && ($res['EXTERNAL_AUTH_ID'] ?? '') === 'email')
			{
				$data['TYPE'] = 'mail';
			}
			elseif ($extranetInstalled && (empty($res['UF_DEPARTMENT']) || (int)($res['UF_DEPARTMENT'][0] ?? 0) <= 0))
			{
				$data['TYPE'] = 'extranet';
			}

			$profiles[$data['ID']] = $data;
		}

		return $profiles;
	}

	private function resolveUrlTemplate(string $urlTemplate): string
	{
		return preg_match('#^(?:/|https?://)#', $urlTemplate) === 1 ? $urlTemplate : self::DEFAULT_URL_TEMPLATE;
	}

	/**
	 * @param array<int, array> $users
	 * @return array<int, array{ID: int, PHOTO: string, PHOTO_SRC: string, FULL_NAME: string, URL: string, TYPE: string}>
	 */
	private function formatCards(array $users, string $nameTemplate, string $urlTemplate): array
	{
		$items = [];
		foreach ($users as $user)
		{
			$items[] = [
				'ID' => (int)$user['ID'],
				'PHOTO' => $user['PHOTO'] ?? '',
				'PHOTO_SRC' => $user['PHOTO_SRC'] ?? '',
				'FULL_NAME' => \CUser::FormatName($nameTemplate, $user),
				'URL' => \CComponentEngine::MakePathFromTemplate(
					$urlTemplate,
					['UID' => $user['ID'], 'user_id' => $user['ID'], 'USER_ID' => $user['ID']]
				),
				'TYPE' => $user['TYPE'] ?? '',
			];
		}

		return $items;
	}

	/**
	 * @return array{ID: int[], answer_id: int, request_id: string, items: array, StatusPage: string}
	 */
	private function emptyResult(string $requestId): array
	{
		return [
			'ID' => [],
			'answer_id' => 0,
			'request_id' => $requestId,
			'items' => [],
			'StatusPage' => 'done',
		];
	}
}
