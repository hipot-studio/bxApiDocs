<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

/**
 * What replaces the content of a dashboard, in the arguments of set_dashboard_content.
 * A null part is kept as it is; an empty list replaces the part with nothing.
 */
final class SetContentCommand
{
	/**
	 * @param array|null $charts Full chart layout: {chartId, width?, height?, sliceName?} each.
	 * @param array|null $filters Full filter configuration: {name, filterType, datasetId?, column?, chartIds?,
	 *     defaultValue?} each.
	 */
	public function __construct(
		public readonly int $dashboardId,
		public readonly ?array $charts = null,
		public readonly ?array $filters = null,
	)
	{
	}
}
