<?php

declare(strict_types=1);

namespace Bitrix\Market\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Market\Internal\Services;
use Bitrix\Market\Internal\Services\AiSearch\Enum\CorrectionMode;

class AiSearch extends Controller
{
	public function getAppsAction(
		Services\AiSearch\AppsSearcher $appsSearcher,
		string $text,
		int $page = 1,
		string $area = '',
		array $order = [],
		CorrectionMode $correctionMode = CorrectionMode::Auto,
	): AjaxJson
	{
		$query = Services\AiSearch\SearchQuery::create($text, $page, $area, $order, $correctionMode);

		$result = $appsSearcher->search($query, Services\AiSearch\Visitor::createCurrent());

		if ($result === null)
		{
			return AjaxJson::createError(new ErrorCollection([new Error('', 'MARKET_AI_SEARCH_UNAVAILABLE')]));
		}

		return AjaxJson::createSuccess($result);
	}

	public function sendEventAction(
		Services\AiSearch\SearchTelemetryTracker $searchTelemetryTracker,
		string $eventType,
		string $appCode,
		string $searchContext,
		int $position = 0,
	): AjaxJson
	{
		return AjaxJson::createSuccess([
			'accepted' => $searchTelemetryTracker->trackClientEvent($eventType, $appCode, $searchContext, $position),
		]);
	}

	protected function getDefaultPreFilters(): array
	{
		$defaultPreFilters = parent::getDefaultPreFilters();
		$defaultPreFilters[] = new ActionFilter\Scope(ActionFilter\Scope::NOT_REST);

		return $defaultPreFilters;
	}
}
