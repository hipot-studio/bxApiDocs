<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\AiAssistant\RemoteMcp\Grid;
use Bitrix\Mobile\Provider\UserRepository;

final class MCPSelector extends JsonController
{
	public function configureActions(): array
	{
		return [
			'getData' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @restMethod mobile.MCPSelector.getData
	 */
	public function getDataAction(): array
	{
		if (!\Bitrix\Main\Loader::includeModule('aiassistant'))
		{
			return [
				'isDisabled' => true,
				'items' => [],
			];
		}

		$mcpFeature = \Bitrix\AiAssistant\RemoteMcp\RemoteMcpFeature::getInstance();
		$mcpFeatureDisabled = $mcpFeature->isFeatureOff();

		if ($mcpFeatureDisabled)
		{
			return [
				'isDisabled' => true,
				'items' => [],
			];
		}

		$preparedServers = $this->prepareServers($this->getServerList());

		$userIds = $this->extractUserIds($preparedServers);
		$users = UserRepository::getByIds($userIds);

		return [
			'disabledByAdmin' => !$mcpFeature->isActive(),
			'disabledByTariff' => !$mcpFeature->isAvailableByTariff(),
			'items' => $preparedServers,
			'users' => $users,
		];
	}

	private function getServerList(int $page = 1): array
	{
		$user = $this->getCurrentUser();
		$userId = $user->getId();

		$remoteMcpSelectorProvider = \Bitrix\Main\DI\ServiceLocator::getInstance()
			->get(\Bitrix\AiAssistant\RemoteMcp\Grid\Provider\McpSelectorProvider::class)
		;

		$pager = new \Bitrix\Main\UI\PageNavigation('nav-selector');
		$filter = (new \Bitrix\AiAssistant\RemoteMcp\Grid\Provider\Params\McpServer\McpServerFilter());
		$pager->setCurrentPage($page);

		$params = new \Bitrix\AiAssistant\RemoteMcp\Grid\Provider\Params\McpServer\McpServerParams(
			pager: $pager,
			filter: $filter,
		);

		return $remoteMcpSelectorProvider->getList($params, $userId);
	}

	private function prepareServers($servers): array
	{
		$mcpFeature = \Bitrix\AiAssistant\RemoteMcp\RemoteMcpFeature::getInstance();

		$items = array_map(fn($item) => [
			'id' => $item['id'],
			'name' => $item['name'],
			'description' => $item['description'],
			'iconUrl' => $item['iconUrl'],
			'isActive' => $item['isActive'],
			'isDisabled' => $mcpFeature->isLocked(),
			'authorizations' => $item['authorizations'],
		], $servers);

		return array_values($items);
	}

	private function extractUserIds(array $servers): array
	{
		$userIds = [];
		foreach ($servers as $server)
		{
			foreach ($server['authorizations'] as $authorization)
			{
				$userIds[] = $authorization['userId'];
			}
		}

		return array_values(array_unique($userIds));
	}
}
