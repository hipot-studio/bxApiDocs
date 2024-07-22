<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;

class SupersetScopeProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-superset-scope';

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = $options;
	}

	public function isAvailable(): bool
	{
		global $USER;

		return is_object($USER) && $USER->isAuthorized();
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addRecentItems($this->getItems([]));
	}

	public function getItems(array $ids): array
	{
		$result = [];
		$scopes = ScopeService::getInstance()->getScopeList();
		foreach ($scopes as $scope)
		{
			$result[] = $this->makeItem($scope);
		}

		return $result;
	}

	private function makeItem(string $scopeCode): Item
	{
		$itemParams = [
			'id' => $scopeCode,
			'entityId' => self::ENTITY_ID,
			'title' => ScopeService::getInstance()->getScopeName($scopeCode),
			'description' => null,
		];

		return new Item($itemParams);
	}
}
