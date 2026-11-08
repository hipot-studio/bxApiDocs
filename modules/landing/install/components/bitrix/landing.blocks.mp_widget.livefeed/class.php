<?php

use Bitrix\Blog\PostSocnetRightsTable;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\UrlManager;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Vibe\Vibe;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\WorkgroupTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

CBitrixComponent::includeComponentClass('bitrix:landing.blocks.mp_widget.base');

class LandingBlocksMainpageWidgetLivefeed extends LandingBlocksMainpageWidgetBase
{
	private const POST_COUNT_DEFAULT = 15;

	/**
	 * The block setting is not trusted to be sane: the widget shows a short feed, not a page of the blog.
	 */
	private const POST_COUNT_LIMIT = 50;

	private const PATH_TO_POST_DEFAULT = '/company/personal/user/#user_id#/blog/#post_id#/';

	/**
	 * Base executable method.
	 * @return void
	 */
	public function executeComponent(): void
	{
		$this->checkParam('TITLE', Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_TITLE'));
		$this->checkParam('COLOR_BUTTON', '#bdc1c6');
		$this->checkParam('COLOR_HEADERS_V2', '#ffffff');

		$this->checkParam('USER_ID', Manager::getUserId());
		$this->checkParam('POST_COUNT', self::POST_COUNT_DEFAULT);
		$this->checkParam('GROUP_ID', null);

		$this->getData();

		parent::executeComponent();
	}

	private function formatDemoDate(string $date, bool $isShort): ?string
	{
		$dateTime = \DateTime::createFromFormat('d.m.Y H:i:s', $date);
		if ($dateTime === false)
		{
			return null;
		}

		$format = $isShort
			? 'SHORT'
			: 'FULL';

		return FormatDate($format, $dateTime->getTimestamp());
	}

	protected function getData(): void
	{
		$useDemoData = false;
		if (Vibe::isUseDemoData())
		{
			$data = $this->getDemoData();
		}
		else
		{
			$data = $this->getRealData();
			if (!isset($data['POSTS']))
			{
				$data = $this->getDemoData();
				$useDemoData = true;
			}
		}

		$this->arResult['TITLE'] = $this->arParams['TITLE'];
		$this->arResult['POSTS'] = $data['POSTS'];
		$this->arResult['USERS'] = $data['USERS'];
		$this->arResult['USERS_ID'] = $data['USERS_ID'];
		$this->arResult['USE_DEMO_DATA'] = $useDemoData;
		$this->arResult['PHRASES'] = [
			'POST_IMPORTANT' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_PHRASES_POST_IMPORTANT'),
			'NAVIGATOR_BUTTON' => $this->getNavigatorButtonPhrases(),
		];

		if (count($data['POSTS']) > 5)
		{
			$this->arResult['IS_SHOW_EXTEND_BUTTON'] = true;
		}
		else
		{
			$this->arResult['IS_SHOW_EXTEND_BUTTON'] = false;
		}
	}

	protected function getDemoData(): array
	{
		return [
			'TITLE' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_TITLE'),
			'POSTS' => [
				[
					'AUTHOR_ID' => '1',
					'TITLE' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_POST_TITLE_1'),
					'DATE_PUBLISH' => $this->formatDemoDate('12.01.2024 14:10:00', false),
					'DATE_PUBLISH_SHORT' => $this->formatDemoDate('12.01.2024 14:10:00', true),
					'RATING_TOTAL_VOTES' => '23',
					'NUM_COMMENTS' => '2',
					'IMG_SRC' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/1.jpg',
					'IMPORTANT' => true,
				],
				[
					'AUTHOR_ID' => '2',
					'TITLE' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_POST_TITLE_2'),
					'DATE_PUBLISH' => $this->formatDemoDate('22.01.2024 13:10:00', false),
					'DATE_PUBLISH_SHORT' =>  $this->formatDemoDate('22.01.2024 13:10:00', true),
					'RATING_TOTAL_VOTES' => '23',
					'NUM_COMMENTS' => '3',
					'IMG_SRC' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/2.jpg',
					'IMPORTANT' => true,
				],
				[
					'AUTHOR_ID' => '3',
					'TITLE' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_POST_TITLE_3'),
					'DATE_PUBLISH' => $this->formatDemoDate('13.02.2024 10:20:00', false),
					'DATE_PUBLISH_SHORT' => $this->formatDemoDate('13.02.2024 10:20:00', true),
					'RATING_TOTAL_VOTES' => '7',
					'NUM_COMMENTS' => '7',
					'IMG_SRC' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/3.jpg',
					'IMPORTANT' => true,
				],
				[
					'AUTHOR_ID' => '4',
					'TITLE' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_POST_TITLE_4'),
					'DATE_PUBLISH' => $this->formatDemoDate('04.01.2024 15:35:00', false),
					'DATE_PUBLISH_SHORT' => $this->formatDemoDate('04.01.2024 15:35:00', true),
					'RATING_TOTAL_VOTES' => '12',
					'NUM_COMMENTS' => '45',
					'IMG_SRC' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/4.jpg',
					'IMPORTANT' => true,
				],
				[
					'AUTHOR_ID' => '1',
					'TITLE' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_POST_TITLE_5'),
					'DATE_PUBLISH' => $this->formatDemoDate('15.03.2024 16:20:00', false),
					'DATE_PUBLISH_SHORT' => $this->formatDemoDate('15.03.2024 16:20:00', true),
					'RATING_TOTAL_VOTES' => '23',
					'NUM_COMMENTS' => '3',
					'IMG_SRC' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/5.jpg',
					'IMPORTANT' => false,
				],
				[
					'AUTHOR_ID' => '3',
					'TITLE' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_POST_TITLE_6'),
					'DATE_PUBLISH' => $this->formatDemoDate('15.03.2024 16:20:00', false),
					'DATE_PUBLISH_SHORT' => $this->formatDemoDate('15.03.2024 16:20:00', true),
					'RATING_TOTAL_VOTES' => '11',
					'NUM_COMMENTS' => '4',
					'IMG_SRC' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/6.jpg',
					'IMPORTANT' => false,
				],
			],
			'USERS' => [
				1 => [
					'ID' => '1',
					'NAME' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_USER_NAME_1'),
					'PERSONAL_PHOTO' => [
						'IMG' => [
							'src' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/7.png',
						],
					],
				],
				2 => [
					'ID' => '2',
					'NAME' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_USER_NAME_2'),
					'PERSONAL_PHOTO' => [
						'IMG' => [
							'src' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/8.png',
						],
					],
				],
				3 => [
					'ID' => '3',
					'NAME' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_USER_NAME_3'),
					'PERSONAL_PHOTO' => [
						'IMG' => [
							'src' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/9.png',
						],
					],
				],
				4 => [
					'ID' => '4',
					'NAME' => Loc::getMessage('LANDING_WIDGET_CLASS_LIVEFEED_DEMO_DATA_USER_NAME_4'),
					'PERSONAL_PHOTO' => [
						'IMG' => [
							'src' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/livefeed/10.png',
						],
					],
				],
			],
			'USERS_ID' => [],
		];
	}

	protected function getRealData(): array
	{
		$data = [];

		if (
			!Loader::includeModule('blog')
			|| !Loader::includeModule('disk')
		)
		{
			return $data;
		}

		$postIds = $this->getPostIdsByGroup($this->getSocnetGroupId());
		if (empty($postIds))
		{
			return $data;
		}

		$previews = $this->getPostPreviews($postIds);

		$res = $this->getPostsQuery($postIds);
		while ($post = $res->Fetch())
		{
			$post['TITLE'] = Emoji::decode((string)($post['TITLE'] ?? ''));

			$preview = $previews[(int)$post['ID']] ?? null;
			if ($preview !== null)
			{
				$post['IMG_SRC'] = $preview;
			}

			$post['PATH'] = CComponentEngine::MakePathFromTemplate(
				self::PATH_TO_POST_DEFAULT,
				[
					'post_id' => (int)$post['ID'],
					'user_id' => $post['AUTHOR_ID'],
				]);
			$post['DATE_PUBLISH_SHORT'] = FormatDate(
				'SHORT',
				MakeTimeStamp($post['DATE_PUBLISH']),
			);

			$data['POSTS'][] = $post;
			$data['USERS_ID'][$post['AUTHOR_ID']] = $post['AUTHOR_ID'];
		}

		if (isset($data['POSTS']) && count($data['POSTS']) !== 0)
		{
			$data['USERS'] = self::getUserData($data['USERS_ID'], [24, 24]);
		}

		return $data;
	}

	/**
	 * Returns the workgroup whose posts are shown: the block parameter first, the portal option next.
	 * Keeps the answer in arParams['SOCNET_GROUP_ID'] - the templates build the link to the group from it.
	 * @return int
	 */
	protected function getSocnetGroupId(): int
	{
		$groupId = 0;
		$sourceValue = $this->arParams['GROUP_ID']['filter'][0]['value'] ?? null;
		if (is_string($sourceValue) && str_starts_with($sourceValue, 'SG'))
		{
			$groupId = (int)substr($sourceValue, 2);
		}
		elseif (Loader::includeModule('socialnetwork'))
		{
			$publicationGroupId = (int)Option::get('landing', 'mainpage_id_publication_group');
			if ($publicationGroupId > 0)
			{
				$groupRow = WorkgroupTable::getRow([
					'select' => ['ID'],
					'filter' => ['=ID' => $publicationGroupId],
				]);
				$groupId = (int)($groupRow['ID'] ?? 0);
			}
		}

		$this->arParams['SOCNET_GROUP_ID'] = $groupId;

		return $groupId;
	}

	/**
	 * Returns ids of the group posts to show, most recently published first.
	 * The rights table is the only place indexed by group, so the ids are taken there
	 * and the post data is loaded by id afterwards.
	 * @param int $groupId Workgroup id.
	 * @return int[]
	 */
	protected function getPostIdsByGroup(int $groupId): array
	{
		if ($groupId <= 0)
		{
			return [];
		}

		$postCount = (int)($this->arParams['POST_COUNT'] ?? 0);
		if ($postCount <= 0)
		{
			$postCount = self::POST_COUNT_DEFAULT;
		}
		$postCount = min($postCount, self::POST_COUNT_LIMIT);

		// DATE_PUBLISH is stored in server time, so the cutoff for scheduled posts is server time as well
		$now = new DateTime();

		$rows = PostSocnetRightsTable::query()
			->setSelect(['POST_ID'])
			->where('ENTITY', 'SG' . $groupId)
			->where('POST.PUBLISH_STATUS', BLOG_PUBLISH_STATUS_PUBLISH)
			->where('POST.DATE_PUBLISH', '<=', $now)
			->setOrder(['POST.DATE_PUBLISH' => 'DESC'])
			->setLimit($postCount)
			->fetchAll()
		;

		return array_values(array_unique(array_map('intval', array_column($rows, 'POST_ID'))));
	}

	/**
	 * Returns arguments of the post data query, or an empty array when there is nothing to ask about.
	 * @param int[] $postIds Blog post ids.
	 * @return array
	 */
	protected function getPostsQueryParams(array $postIds): array
	{
		// an empty list must never reach CBlogPost::GetList: no filter plus no top count means a full scan
		if (empty($postIds))
		{
			return [];
		}

		return [
			'order' => ['DATE_PUBLISH' => 'DESC'],
			'filter' => ['@ID' => $postIds],
			'nav' => ['nTopCount' => count($postIds)],
			'select' => [
				'ID',
				'TITLE',
				'AUTHOR_ID',
				'DATE_PUBLISH',
				'NUM_COMMENTS',
				'RATING_TOTAL_VOTES',
			],
		];
	}

	/**
	 * @param int[] $postIds Blog post ids.
	 * @return CDBResult
	 */
	protected function getPostsQuery(array $postIds): CDBResult
	{
		$params = $this->getPostsQueryParams($postIds);
		if (empty($params))
		{
			$result = new CDBResult();
			$result->InitFromArray([]);

			return $result;
		}

		return CBlogPost::GetList(
			$params['order'],
			$params['filter'],
			false,
			$params['nav'],
			$params['select'],
		);
	}

	/**
	 * Returns preview image url of the first attachment for every post which has one.
	 * @param int[] $postIds Blog post ids.
	 * @return array<int, string>
	 */
	protected function getPostPreviews(array $postIds): array
	{
		if (empty($postIds))
		{
			return [];
		}

		[$connectorClass, $moduleId] = Driver::getInstance()
			->getUserFieldManager()
			->getConnectorDataByEntityType('BLOG_POST')
		;

		$attachedObjects = AttachedObject::getModelList([
			'filter' => [
				'=ENTITY_TYPE' => $connectorClass,
				'ENTITY_ID' => $postIds,
				'=MODULE_ID' => $moduleId,
			],
			'order' => ['ID' => 'ASC'],
			'extra' => [
				'FILE_CONTENT_TYPE' => 'OBJECT.FILE_CONTENT.CONTENT_TYPE',
			],
		]);

		$previews = [];
		$handledPostIds = [];
		foreach ($attachedObjects as $attachedObject)
		{
			$postId = (int)$attachedObject->getEntityId();
			if (isset($handledPostIds[$postId]))
			{
				continue;
			}
			$handledPostIds[$postId] = true;

			$contentType = $attachedObject->getExtra()->get('FILE_CONTENT_TYPE');
			if (!is_string($contentType))
			{
				continue;
			}

			if (str_starts_with($contentType, 'image/'))
			{
				$previews[$postId] = UrlManager::getUrlUfController(
					'show',
					[
						'attachedId' => $attachedObject->getId(),
					],
				);
			}
		}

		return $previews;
	}
}
