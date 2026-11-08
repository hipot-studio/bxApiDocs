<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Configuration\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The rollout switch of the chart analysis through BitrixGPT. Off by default.
 *
 * The flag is asked through Configuration\Feature::isChartAnalysisEnabled() and nowhere else.
 */
final class ChartAnalysisFlag extends AbstractFlag
{
}
