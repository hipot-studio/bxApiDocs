<?php

namespace Bitrix\Socialnetwork\Controller;

use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Socialnetwork\Item\ContentView\ContentViewAccessService;
use Bitrix\Socialnetwork\Item\ContentView\UserListService;
use Bitrix\Socialnetwork\Item\UserContentView;

class ContentView extends Base
{
	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		$configureActions['set'] = [
			'+prefilters' => [
				new ActionFilter\CloseSession(),
			]
		];

		return $configureActions;
	}

	public function setAction(array $params = []): ?array
	{
		global $USER;

		$xmlIdList = (
			isset($params["viewXMLIdList"])
			&& is_array($params["viewXMLIdList"])
				? $params["viewXMLIdList"]
				: []
		);

		$context = ($params['context'] ?? '');

		if (!Loader::includeModule('socialnetwork'))
		{
			$this->addError(new Error('Cannot include Socialnetwork module', 'SONET_CONTROLLER_CONTENTVIEW_NO_SOCIALNETWORK_MODULE'));
			return null;
		}

		$signer = new \Bitrix\Main\Engine\ActionFilter\Service\Token($USER->getId());

		foreach ($xmlIdList as $key => $item)
		{
			if (empty($item['xmlId']))
			{
				unset($xmlIdList[$key]);
				continue;
			}

			if (!empty($item['signedKey']))
			{
				try
				{
					if ($signer->unsign($item['signedKey'], $item['xmlId']) === $item['xmlId'])
					{
						$xmlIdList[$key]['checkAccess'] = false;
					}
					else
					{
						unset($xmlIdList[$key]);
					}
				}
				catch(\Exception $e)
				{
					$xmlIdList[$key]['checkAccess'] = true;
				}
			}
			else
			{
				$xmlIdList[$key]['checkAccess'] = true;
			}
		}

		UserContentView::set([
			'xmlIdList' => $xmlIdList,
			'context' => $context,
			'userId' => $this->getCurrentUser()->getId(),
		]);

		return [
			'SUCCESS' => 'Y'
		];
	}

	public function getListAction(array $params = []): ?array
	{
		$contentId = (
			isset($params['contentId'])
			&& is_string($params['contentId'])
				? trim($params['contentId'])
				: ''
		);

		$page = (
			isset($params['page'])
			&& (int)$params['page'] > 0
				? (int)$params['page']
				: 1
		);

		$pathToUserProfile = (
			isset($params['pathToUserProfile'])
			&& is_string($params['pathToUserProfile'])
				? trim($params['pathToUserProfile'])
				: ''
		);

		if (!Loader::includeModule('socialnetwork'))
		{
			$this->addError(new Error('Cannot include Socialnetwork module', 'SONET_CONTROLLER_CONTENTVIEW_NO_SOCIALNETWORK_MODULE'));
			return null;
		}

		$userListService = new UserListService(new ContentViewAccessService());

		return $userListService->getUserList(
			$contentId,
			$this->getCurrentUser()->getId(),
			$page,
			$pathToUserProfile,
		);
	}
}
