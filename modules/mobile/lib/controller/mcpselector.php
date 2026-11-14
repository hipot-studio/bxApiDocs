<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\AiAssistant\RemoteMcp\Enum\SharingOptionType;
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

		return $this->buildPayload(\Bitrix\AiAssistant\RemoteMcp\RemoteMcpFeature::getInstance());
	}

	private function buildPayload(object $mcpFeature): array
	{
		// mobile and aiassistant are updated separately, so the installed aiassistant may predate the regional lock.
		$hasRegionalLock = method_exists($mcpFeature, 'resolveLock')
			&& method_exists($mcpFeature, 'isFeatureMissingInRegion')
		;

		// A region without the feature answers exactly the way a switched off feature does: no
		// items and no lock at all, so neither the current nor an older app opens the restriction
		// screen with a reason that does not apply there.
		if ($mcpFeature->isFeatureOff() || ($hasRegionalLock && $mcpFeature->isFeatureMissingInRegion()))
		{
			return [
				'isDisabled' => true,
				'items' => [],
			];
		}

		// The lock answers the same for the whole payload, so it is resolved once and handed down.
		$lock = $hasRegionalLock ? $mcpFeature->resolveLock() : null;
		$isLocked = $hasRegionalLock ? $lock !== null : $mcpFeature->isLocked();

		$preparedServers = $this->prepareServers($this->getServerList(), $isLocked);

		$userIds = $this->extractUserIds($preparedServers);
		$users = UserRepository::getByIds($userIds);

		return [
			'disabledByAdmin' => !$mcpFeature->isActive(),
			'disabledByTariff' => !$mcpFeature->isAvailableByTariff(),
			'disabledBySubscription' => !$mcpFeature->isAvailableBySubscription(),
			// Reason and the way out of it: the screen shows the reason of its own region and opens
			// the restriction slider by the feature id.
			'lockReason' => $lock?->reason->value ?? '',
			'sliderFeatureId' => $lock?->sliderFeatureId,
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
		$filter->setAgentMode(false);
		$pager->setCurrentPage($page);

		$params = new \Bitrix\AiAssistant\RemoteMcp\Grid\Provider\Params\McpServer\McpServerParams(
			pager: $pager,
			filter: $filter,
		);

		return $remoteMcpSelectorProvider->getList($params, $userId);
	}

	private function prepareServers($servers, bool $isLocked): array
	{
		$items = array_map(fn($item) => [
			'id' => $item['id'],
			'name' => $item['name'],
			'description' => $item['description'],
			'iconUrl' => $item['iconUrl'],
			'isActive' => $item['isActive'],
			'isDisabled' => $isLocked,
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
