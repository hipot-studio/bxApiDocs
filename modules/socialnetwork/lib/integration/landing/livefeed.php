<?php
namespace Bitrix\Socialnetwork\Integration\Landing;

use Bitrix\Blog\PostTable;
use Bitrix\Blog\PostSocnetRightsTable;
use Bitrix\Main;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Landing;
use Bitrix\Main\FileTable;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\Log\ViewerAccess;
use Bitrix\Socialnetwork\LogRightTable;
use Bitrix\Socialnetwork\LogTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Socialnetwork\Helper\Workgroup\Access;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;

class Livefeed extends Landing\Source\DataLoader
{
	private array $selectedGroupIds = [];

	/**
	 * @return void
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * @return array
	 */
	private static function getOrderFields()
	{
		return [
			[
				'ID' => 'LOG_DATE',
				'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_FIELD_LOG_DATE')
			]
		];
	}

	/**
	 * @param Main\Event $event
	 * @return Main\EventResult
	 */
	public static function onBuildSourceListHandler(Main\Event $event)
	{
		/** @var Landing\Source\Selector $selector */
		$selector = $event->getParameter('SELECTOR');

		$siteId = null;

		$restrictions = $selector->getModuleRestrictions('socialnetwork');
		if (
			!empty($restrictions)
			&& !empty($restrictions['SITE_ID'])
		)
		{
			$siteId = $restrictions['SITE_ID'];
		}
		unset($restrictions);

		$dataSettings = [
			'ORDER' => self::getOrderFields(),
			'FIELDS' => [
				[
					'ID' => 'TITLE',
					'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_FIELD_TITLE'),
					'TYPE' => Landing\Node\Type::TEXT
				],
				[
					'ID' => 'PREVIEW_TEXT',
					'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_FIELD_PREVIEW_TEXT'),
					'TYPE' => Landing\Node\Type::TEXT
				],
				[
					'ID' => 'DETAIL_TEXT',
					'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_FIELD_DETAIL_TEXT'),
					'TYPE' => Landing\Node\Type::TEXT
				],
				[
					'ID' => 'AUTHOR_NAME_FORMATTED',
					'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_FIELD_AUTHOR_NAME_FORMATTED'),
					'TYPE' => Landing\Node\Type::TEXT
				],
				[
					'ID' => 'LOG_DATE',
					'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_FIELD_LOG_DATE'),
					'TYPE' => Landing\Node\Type::TEXT
				],
				[
					'ID' => 'PICTURE',
					'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_FIELD_PICTURE'),
					'TYPE' => Landing\Node\Type::IMAGE
				],
				[
					'ID' => 'AUTHOR_AVATAR',
					'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_FIELD_AUTHOR_AVATAR'),
					'TYPE' => Landing\Node\Type::IMAGE
				],
				[
					'ID' => 'LINK',
					'NAME' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_ACTIONS'),
					'TYPE' => Landing\Node\Type::LINK,
					'ACTIONS' => $selector->getDefaultLinkActions()
				]
			]
		];

		$result = [];

		$result[] = [
			'SOURCE_ID' => 'livefeed',
			'TITLE' => Loc::getMessage('SONET_LANDING_DYNAMIC_BLOCK_LIVEFEED_TITLE2'),
			'TYPE' => Landing\Source\Selector::SOURCE_TYPE_COMPONENT,
			'SETTINGS' => [
				'COMPONENT_NAME' => 'bitrix:socialnetwork.landing.livefeed.selector',
				'COMPONENT_TEMPLATE_NAME' => '.default',
				'COMPONENT_PARAMS' => [
					'SITE_ID' => $siteId,
				],
				"USE_UI_TOOLBAR" => "Y", // TODO: remove this key after stable update landing
				'WRAPPER' => [
					'USE_PADDING' => false,
					'PLAIN_VIEW' => false,
					'USE_UI_TOOLBAR' => 'Y'
				]
			],
			'SOURCE_FILTER' => [],
			'DATA_SETTINGS' => $dataSettings,
			'DATA_LOADER' => __CLASS__
		];


		unset($selector);

		return new Main\EventResult(Main\EventResult::SUCCESS, $result, 'socialnetwork');
	}

	public static function onBeforeDynamicBlockCache(Main\Event $event): ?Main\EventResult
	{
		$sourceIds = $event->getParameter('SOURCE_IDS');
		if (is_array($sourceIds) && in_array('socialnetwork:livefeed', $sourceIds, true))
		{
			return new Main\EventResult(Main\EventResult::ERROR, null, 'socialnetwork');
		}

		return null;
	}

	/**
	 * @return array
	 */
	private function getRightsFilter()
	{
		global $CACHE_MANAGER;

		$result = [];

		$settingsFilter = $this->getFilter();
		if (!is_array($settingsFilter))
		{
			$settingsFilter = [];
		}

		foreach ($settingsFilter as $filterField)
		{
			if (
				!empty($filterField['key'])
				&& !empty($filterField['value'])
				&& $filterField['key'] == 'GROUP_ID'
				&& $filterField['value'] != 'all'
			)
			{
				$result[] = $filterField['value'];

				if (defined("BX_COMP_MANAGED_CACHE"))
				{
					$CACHE_MANAGER->registerTag("landing_dynamic_filter_".$filterField['value']);
				}
			}
		}

		$groupIdList = array_unique(array_filter(array_map(function($val) {
			$res = false;
			if (preg_match('/^SG(\d+)$/i', $val, $matches))
			{
				$res = intval($matches[1]);
			}

			return $res;
		}, $result), function ($val) {
			return ($val > 0);
		}));

		$this->selectedGroupIds = array_values($groupIdList);
		return $this->resolveEligibleGroups($this->selectedGroupIds);
	}

	private function resolveEligibleGroups(array $groupIdList): array
	{
		if (!$groupIdList)
		{
			return ['viewer' => [], 'export' => []];
		}
		$result = [];
		$res = WorkgroupTable::getList([
			'filter' => [
				'=LANDING' => 'Y',
				'=ACTIVE' => 'Y',
				'@ID' => $groupIdList
			],
			'select' => [ 'ID', 'VISIBLE' ]
		]);
		$groups = [];
		while ($workgroupFields = $res->fetch())
		{
			$groups[(int)$workgroupFields['ID']] = $workgroupFields['VISIBLE'] === 'Y';
		}

		$userId = \Bitrix\Socialnetwork\Helper\User::getCurrentUserId();
		if ($userId === 0)
		{
			foreach ($groups as $groupId => $visible)
			{
				if ($visible)
				{
					$result[] = 'SG' . $groupId;
				}
			}
		}
		elseif (Access::isRuleEnabled(GroupDictionary::VIEW))
		{
			foreach (array_chunk(array_keys($groups), GroupAccessController::MAX_MASS_CHECK_ITEMS) as $groupIds)
			{
				$access = GroupAccessController::getAccessibleGroups($userId, GroupDictionary::VIEW, $groupIds);
				foreach ($access as $groupId => $view)
				{
					if ($view->canView)
					{
						$result[] = 'SG' . $groupId;
					}
				}
			}
		}
		else
		{
			foreach ($groups as $groupId => $visible)
			{
				if (Access::canViewWithMinimalData([
					'groupId' => $groupId,
					'userId' => $userId,
					'visible' => $visible,
				]))
				{
					$result[] = 'SG' . $groupId;
				}
			}
		}

		return ['viewer' => $result, 'export' => $this->getExportGroups(array_keys($groups))];
	}

	/**
	 * @return array
	 */
	private function getAuthorFilter()
	{
		$result = [];

		$settingsFilter = $this->getFilter();
		if (!is_array($settingsFilter))
		{
			$settingsFilter = [];
		}

		foreach ($settingsFilter as $filterField)
		{
			if (
				!empty($filterField['key'])
				&& !empty($filterField['value'])
				&& $filterField['key'] == 'AUTHOR_ID'
				&& $filterField['value'] != 'all'
			)
			{
				$result[] = $filterField['value'];
			}
		}

		$authorIdList = array_filter(array_map(function($val) {
			$res = false;
			if (preg_match('/^U(\d+)$/i', $val, $matches))
			{
				$res = intval($matches[1]);
			}

			return $res;
		}, $result), function ($val) {
			return ($val > 0);
		});

		if (empty($authorIdList))
		{
			return $result;
		}

		$result = [];
		$res = UserTable::getList([
			'filter' => [
				'=ACTIVE' => 'Y',
				'@ID' => $authorIdList
			],
			'select' => [ 'ID' ]
		]);
		while ($userFields = $res->fetch())
		{
			$result[] = $userFields['ID'];
		}

		return $result;
	}

	/**
	 * @return array
	 */
	public function getElementListData()
	{
		$this->seo->clear();

		$result = [];

		$rightsFilter = $this->getRightsFilter();
		$authorFilter = $this->getAuthorFilter();

		if (empty($rightsFilter['viewer']) && empty($rightsFilter['export']))
		{
			return $result;
		}

		$orderFields = self::getOrderFields();
		$order = $this->getOrder();

		if (!is_array($order))
		{
			$order = [];
		}

		$livefeedOrder = [];

		if (
			!empty($order)
			&& !empty($order['by'])
			&& !empty($order['order'])
		)
		{
			foreach($orderFields as $field)
			{
				if ($order['by'] == $field['ID'])
				{
					$livefeedOrder = [
						$order['by'] => $order['order']
					];
				}
			}
		}

		if (empty($livefeedOrder))
		{
			$livefeedOrder = [
				'LOG_DATE' => 'DESC'
			];
		}

		$livefeedFilter = [
			'LOG_RIGHTS' => $rightsFilter['viewer'],
			'EVENT_ID' => \CSocNetLogTools::findFullSetByEventID('blog_post'),
			'<=LOG_DATE' => 'NOW',
		];

		if (
			!empty($authorFilter)
			&& is_array($authorFilter)
			&& !empty($authorFilter[0])
		)
		{
			$livefeedFilter['USER_ID'] = intval($authorFilter[0]);
			$entityTypes = [];
			foreach (\CSocNetAllowed::GetAllowedEntityTypesDesc() as $type => $description)
			{
				if (($description['USE_CB_FILTER'] ?? null) === 'Y')
				{
					$entityTypes[] = $type;
				}
			}
			if ($entityTypes)
			{
				$livefeedFilter['ENTITY_TYPE'] = $entityTypes;
			}
		}

		$settings = [
			'order' => $livefeedOrder,
			'exportGroups' => $rightsFilter['export'],
			'filter' => $livefeedFilter,
			'limit' => [
				'nTopCount' => $this->getLimit()
			]
		];

		$result = $this->getElementsInternal($settings);

		return $result;
	}

	/**
	 * @param mixed $element
	 * @return array
	 */
	public function getElementData($element)
	{
		$this->seo->clear();

		$result = [];
		if (!is_string($element) && !is_int($element))
			return $result;
		$element = (int)$element;
		if ($element <= 0)
			return $result;

		$rightsFilter = $this->getRightsFilter();
		if (empty($rightsFilter['viewer']) && empty($rightsFilter['export']))
		{
			return $result;
		}

		$settings = [
			'order' => ['LOG_DATE' => 'DESC'],
			'exportGroups' => $rightsFilter['export'],
			'filter' => [
				'SOURCE_ID' => $element,
				'LOG_RIGHTS' => $rightsFilter['viewer'],
				'EVENT_ID' => \CSocNetLogTools::findFullSetByEventID('blog_post'),
				'<=LOG_DATE' => 'NOW',
			],
			'limit' => [
				'nTopCount' => 1
			]
		];

		$result = $this->getElementsInternal($settings);

		if (!empty($result))
		{
			$current = reset($result);
			if (!empty($current))
			{
				$this->seo->setTitle($current['TITLE']);
			}
			unset($current);
		}
		return $result;
	}

	private function getExportGroups(array $groupIds): array
	{
		if (!$groupIds || !Main\Loader::includeModule('blog'))
		{
			return [];
		}

		$permissions = \CSocNetFeaturesPerms::GetOperationPerm('G', $groupIds, 'blog', 'view_post');
		$result = [];
		foreach ($groupIds as $groupId)
		{
			$code = 'SG' . $groupId . '_K';
			$roles = \CBlogPost::getFullGroupRoleSet($permissions[$groupId] ?? '', 'SG' . $groupId . '_');
			if (in_array($code, $roles, true))
			{
				$result[] = (int)$groupId;
			}
		}

		return $result;
	}

	private function buildViewerFilter(array $groupCodes): ?ConditionTree
	{
		global $USER;

		if (!$groupCodes || !is_object($USER))
		{
			return null;
		}
		$viewer = ViewerAccess::createLogIdQuery([
			'CHECK_RIGHTS' => 'Y', 'USER_ID' => (int)$USER->GetID(),
		], []);
		if ($viewer === null)
		{
			return null;
		}

		return Query::filter()->whereIn('ID', $viewer)->whereIn('ID',
			LogRightTable::query()->setSelect(['LOG_ID'])->whereIn('GROUP_CODE', $groupCodes)
		);
	}

	private function buildOrdinaryFilter(array $groupIds): ?ConditionTree
	{
		if (!$groupIds)
		{
			return null;
		}

		$ordinary = Query::filter()->logic('or');
		foreach ($groupIds as $groupId)
		{
			$code = 'SG' . $groupId;
			$group = Query::filter();
			foreach ([$code, $code . '_K'] as $audience)
			{
				$group->whereIn('ID', LogRightTable::query()->setSelect(['LOG_ID'])
					->where('GROUP_CODE', $audience)
				)->whereIn('SOURCE_ID', PostSocnetRightsTable::query()->setSelect(['POST_ID'])
					->where('ENTITY_TYPE', 'SG')->where('ENTITY_ID', $groupId)->where('ENTITY', $audience)
				);
			}
			$ordinary->where($group);
		}

		return $ordinary;
	}

	private function buildAuthorizedPostQuery(
		array $settings, array $rights, string $direction, array $postIds = [],
	): ?Query
	{
		$viewer = $this->buildViewerFilter($rights['viewer']);
		$ordinary = $this->buildOrdinaryFilter($rights['export']);
		if (($viewer === null && $ordinary === null) || empty($settings['filter']['EVENT_ID']))
		{
			return null;
		}
		$permission = Query::filter()->logic('or');
		if ($viewer !== null)
		{
			$permission->where($viewer);
		}
		if ($ordinary !== null)
		{
			$permission->where($ordinary);
		}
		$now = new SqlExpression(Main\Application::getConnection()->getSqlHelper()->getCurrentDateTimeFunction());
		$aggregate = new ExpressionField('SORT_DATE', $direction === 'ASC' ? 'MIN(%s)' : 'MAX(%s)', ['LOG_DATE']);
		$aggregate->configureValueField(new DatetimeField('SORT_DATE'));
		$query = LogTable::query()
			->registerRuntimeField(new Reference('POST', PostTable::class,
				Join::on('this.SOURCE_ID', 'ref.ID'), ['join_type' => 'INNER']))
			->registerRuntimeField($aggregate)
			->setSelect(['SOURCE_ID', 'SORT_DATE'])
			->where($permission)
			->whereIn('EVENT_ID', $settings['filter']['EVENT_ID'])
			->where(Query::filter()->logic('or')->whereNull('INACTIVE')->where('INACTIVE', '!=', 'Y'))
			->where('LOG_DATE', '<=', $now)
			->where('POST.PUBLISH_STATUS', BLOG_PUBLISH_STATUS_PUBLISH)
			->where('POST.DATE_PUBLISH', '<=', $now)
			->setGroup(['SOURCE_ID'])
			->setOrder(['SORT_DATE' => $direction, 'SOURCE_ID' => $direction])
		;
		foreach (['SOURCE_ID', 'USER_ID'] as $field)
		{
			if (isset($settings['filter'][$field]))
			{
				$query->where($field, $settings['filter'][$field]);
			}
		}
		if (!empty($settings['filter']['ENTITY_TYPE']))
		{
			$query->whereIn('ENTITY_TYPE', $settings['filter']['ENTITY_TYPE']);
		}
		if ($postIds)
		{
			$query->whereIn('SOURCE_ID', $postIds);
		}

		return $query;
	}

	private function selectPostIds(array $settings): array
	{
		$direction = strtoupper($settings['order']['LOG_DATE'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
		$query = $this->buildAuthorizedPostQuery($settings, [
			'viewer' => $settings['filter']['LOG_RIGHTS'], 'export' => $settings['exportGroups'],
		], $direction);
		if ($query === null)
		{
			return [];
		}

		$params = $settings['limit'];
		$limit = is_array($params) ? (int)($params['nTopCount'] ?? 0) : (int)$params;
		if ($limit > 0)
		{
			$query->setLimit($limit);
		}
		else
		{
			$total = (int)$query->queryCountTotal();
			$window = (new LivefeedNavigation())->normalize($total, $params);
			if ($window === null)
			{
				return [];
			}
			if (!$window['showAll'])
			{
				$query->setLimit($window['limit'])->setOffset($window['offset']);
			}
		}
		$ids = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['SOURCE_ID'];
		}

		return $this->revalidateSelectedPostIds($settings, $ids, $direction);
	}

	private function revalidateSelectedPostIds(array $settings, array $ids, string $direction): array
	{
		if (!$ids)
		{
			return [];
		}
		$rights = $this->resolveEligibleGroups($this->selectedGroupIds);
		$query = $this->buildAuthorizedPostQuery($settings, $rights, $direction, $ids);
		if ($query === null)
		{
			return [];
		}
		$allowed = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$allowed[(int)$row['SOURCE_ID']] = true;
		}

		return array_values(array_filter($ids, static fn(int $id): bool => isset($allowed[$id])));
	}

	private function getElementsInternal(array $settings): array
	{
		global $USER;

		if (!is_object($USER) || !Main\Loader::includeModule('blog'))
		{
			return [];
		}

		$ids = $this->selectPostIds($settings);
		return $ids ? $this->loadPostPayload($ids) : [];
	}

	/**
	 * @param array $blogPostIdList
	 * @return array
	 */
	private function loadPostPayload(array $blogPostIdList): array
	{
		global $CACHE_MANAGER, $USER_FIELD_MANAGER;
		$result = [];

		$pathToSmile = Main\Config\Option::get("socialnetwork", "smile_page", false, SITE_ID);
		$pathToSmile = ($pathToSmile ? $pathToSmile : "/bitrix/images/socialnetwork/smile/");

		$parserParams = Array(
			"imageWidth" => 500,
			"imageHeight" => 500,
		);

		$allow = [
			"HTML" => "N",
			"ANCHOR" => "Y",
			"BIU" => "Y",
			"IMG" => "Y",
			"QUOTE" => "Y",
			"CODE" => "Y",
			"FONT" => "Y",
			"LIST" => "Y",
			"SMILES" => "Y",
			"NL2BR" => "N",
			"VIDEO" => "Y",
			"USER" => "N",
			"TAG" => "Y",
			"SHORT_ANCHOR" => "Y"
		];
		if(Main\Config\Option::get("blog","allow_video", "Y") != "Y")
		{
			$allow["VIDEO"] = "N";
		}


		$diskInstalled = Main\Loader::includeModule('disk');

		if (
			!empty($blogPostIdList)
			&& Main\Loader::includeModule('blog')
		)
		{
			if ($diskInstalled)
			{
				$driver = \Bitrix\Disk\Driver::getInstance();
				$urlManager = $driver->getUrlManager();
			}


			$query = new \Bitrix\Main\Entity\Query(PostTable::getEntity());
			$query->addOrder('DATE_PUBLISH', 'DESC');
			$query->addFilter('@ID', $blogPostIdList);
			$query->addSelect('ID');
			$query->addSelect('MICRO');
			$query->addSelect('TITLE');
			$query->addSelect('DETAIL_TEXT');
			$query->addSelect('AUTHOR_ID');
			$query->addSelect('DATE_PUBLISH');

			$query->registerRuntimeField(
				'',
				new \Bitrix\Main\Entity\ReferenceField('U',
					UserTable::getEntity(),
					array(
						'=ref.ID' => 'this.AUTHOR_ID'
					),
					array('join_type' => 'INNER')
				)
			);
			$query->addSelect('U.PERSONAL_PHOTO', 'AUTHOR_AVATAR');
			$query->addSelect('U.NAME', 'AUTHOR_NAME');
			$query->addSelect('U.LAST_NAME', 'AUTHOR_LAST_NAME');
			$query->addSelect('U.SECOND_NAME', 'AUTHOR_SECOND_NAME');
			$query->addSelect('U.LOGIN', 'AUTHOR_LOGIN');

			$iterator = $query->exec();
			while ($row = $iterator->fetch())
			{
				if (defined("BX_COMP_MANAGED_CACHE"))
				{
					$CACHE_MANAGER->registerTag("blog_post_".$row['ID']);
				}

				$attachedFilesList = [];

				if ($diskInstalled)
				{
					$res = \Bitrix\Disk\AttachedObject::getList(array(
						'filter' => array(
							'=ENTITY_TYPE' => \Bitrix\Disk\Uf\BlogPostConnector::className(),
							'ENTITY_ID' => $row
						),
						'select' => array('ID', 'OBJECT_ID', 'FILENAME' => 'OBJECT.NAME')
					));
					foreach ($res as $attachedObjectFields)
					{
						$attachedObjectFields['URL'] = (\Bitrix\Disk\TypeFile::isImage($attachedObjectFields['FILENAME'])
							? $urlManager->getUrlUfController('show', array('attachedId' => $attachedObjectFields['ID']))
							: ''
						);
						$attachedFilesList[] = $attachedObjectFields;
					}
				}

				$detailTextInOneString = str_replace("\r\n", "", $row["DETAIL_TEXT"]);

				$inlineAttachmentsList = [];
				if (preg_match_all('/\[DISK\sFILE\sID=([n]*)(\d+)\]/', $row["DETAIL_TEXT"], $matches))
				{
					foreach($matches[2] as $key => $value)
					{
						$inlineAttachmentsList[] = [
							'ID' => $value,
							'KEY' => ($matches[1][$key] === 'n' ? 'OBJECT_ID' : 'ID'),
							'POSITION' => mb_strpos($detailTextInOneString, $matches[0][$key])
						];
					}
				}

				$picture = '';
				$diskPicturePosition = false;
				foreach($inlineAttachmentsList as $inlineAttachment)
				{
					foreach($attachedFilesList as $attachedFile)
					{
						if(
							$attachedFile[$inlineAttachment['KEY']] == $inlineAttachment['ID']
							&& !empty($attachedFile['URL'])
						)
						{
							$picture = [
								'alt' => (!empty($attachedFile['FILENAME']) ? $attachedFile['FILENAME'] : ''),
								'src' => $attachedFile['URL']
							];
							$diskPicturePosition = $inlineAttachment['POSITION'];
							break;
						}
					}
					if(!empty($picture))
					{
						break;
					}
				}

				$imgPattern = '/\[IMG\s+WIDTH\s*=\s*\d+\s+HEIGHT\s*=\s*\d+\s*\](.+?)\[\/IMG\]/isu';
				$videoPattern = '/\[VIDEO[^\]]*](.+?)\[\/VIDEO\]/isu';
				$detailText = \Bitrix\Socialnetwork\Helper\Mention::clear($row['DETAIL_TEXT']);

				if (
					preg_match_all($imgPattern, $detailText, $matches)
					&& !empty($matches[0])
					&& !empty($matches[0][0])
				)
				{
					if (
						$diskPicturePosition === false
						|| mb_strpos($detailTextInOneString, $matches[0][0]) < $diskPicturePosition
					)
					{
						$picture = [
							'alt' => '',
							'src' => $matches[1][0]
						];
					}
				}

				$parser = new \blogTextParser(false, $pathToSmile, []);
				$parser->LAZYLOAD = "N";

				$postFields = $USER_FIELD_MANAGER->getUserFields("BLOG_POST", $row['ID'], LANGUAGE_ID);
				if (!empty($postFields["UF_BLOG_POST_FILE"]))
				{
					$parser->arUserfields = array("UF_BLOG_POST_FILE" => array_merge($postFields["UF_BLOG_POST_FILE"], ["TAG" => "DOCUMENT ID"]));
				}

				$clearedText = $detailText;

				$clearedText = preg_replace(
					[ $imgPattern, $videoPattern ],
					'',
					$clearedText
				);
				$clearedText = preg_replace(
					'/\[URL(.*?)]([^\]\s]{20,})\[\/URL\]/isu',
					'',
					$clearedText
				);
				$clearedText = preg_replace(
					'/\[URL(.*?)]((?:[^\]\s]{1,19}\s+)+)\[\/URL\]/isu',
					'\\2',
					$clearedText
				);
				$clearedText = preg_replace(
					'/\[URL(.*?)]((?:[^\]\s]{1,19}\s+)+)\[\/URL\]/isu',
					'\\2',
					$clearedText
				);
				$clearedText = \blogTextParser::killAllTags($clearedText);

				$title = (
					$row["MICRO"] == "Y"
						? truncateText($clearedText, 100)
						: htmlspecialcharsEx($row["TITLE"])
				);

				$authorAvatar = '';
				$authorName = '';
				if (intval($row['AUTHOR_AVATAR']) > 0)
				{
					$fileRes = FileTable::getById(intval($row['AUTHOR_AVATAR']));
					if ($fileFields = $fileRes->fetch())
					{
						$authorAvatar = [
							'alt' => '',
							'src' => \CFile::getFileSrc($fileFields)
						];
					}
				}
				if (intval($row['AUTHOR_ID']) > 0)
				{
					$authorName = \CUser::formatName(\CSite::getNameFormat(), [
						"NAME" => $row["AUTHOR_NAME"],
						"LAST_NAME" => $row["AUTHOR_LAST_NAME"],
						"SECOND_NAME" => $row["AUTHOR_SECOND_NAME"],
						"LOGIN" => $row["AUTHOR_LOGIN"],
						"NAME_LIST_FORMATTED" => "",
					]);
				}
				if ($row['DATE_PUBLISH'] instanceof \Bitrix\Main\Type\DateTime)
				{
					$logDate = $row['DATE_PUBLISH']->format(\Bitrix\Main\Type\Date::getFormat());
				}

				$result[] = [
					'ID' => $row['ID'],
					'TITLE' => $title,
					'PREVIEW_TEXT' => truncateText($clearedText, 255),
					'DETAIL_TEXT' => $parser->convert($detailText, false, [], $allow, $parserParams),
					'PICTURE' => $picture,
					'AUTHOR_AVATAR' => $authorAvatar,
					'AUTHOR_NAME_FORMATTED' => $authorName,
					'LOG_DATE' => $logDate,
				];
			}
			if (!empty($result))
			{
				$positions = array_flip($blogPostIdList);
				usort($result, static fn(array $a, array $b): int =>
					$positions[(int)$a['ID']] <=> $positions[(int)$b['ID']]
				);
			}
		}

		return $result;
	}

	/**
	 * @param string $code Log right code
	 * @return void
	 */
	public static function onSocNetLogRightsAddHandler($code)
	{
		global $CACHE_MANAGER;

		if (
			defined("BX_COMP_MANAGED_CACHE")
			&& $code <> ''
			&& preg_match('/^SG(\d+)$/i', $code, $matches)
			&& Main\ModuleManager::isModuleInstalled('landing')
		)
		{
			$CACHE_MANAGER->clearByTag("landing_dynamic_filter_".$code);
		}
	}

	/**
	 * @param mixed $filter
	 * @return array
	 */
	public function normalizeFilter($filter)
	{
		if (!is_array($filter))
		{
			return [];
		}
		if (empty($filter))
		{
			return $filter;
		}

		$result = [];
		foreach ($filter as $row)
		{
			if (empty($row) || !is_array($row))
			{
				continue;
			}
			if (empty($row['key']) || empty($row['value']))
			{
				continue;
			}
			$result[] = $row;
		}
		unset($row);

		if (!empty($result))
		{
			Main\Type\Collection::sortByColumn($result, ['key' => SORT_ASC]);
		}
		return $result;
	}
}
