<?php

namespace Bitrix\TimemanMobile\Infrastructure\Controllers;

use Bitrix\Timeman\V2\Infrastructure\Controller;
use Bitrix\TimemanMobile\Infrastructure\Controllers\Trait\ReportSettingsPayloadTrait;

final class Report extends Base
{
	use ReportSettingsPayloadTrait;

	/**
	 * @ajaxAction timemanmobile.Report.getUserReports
	 */
	public function getUserReportsAction(): ?array
	{
		$result = $this->forward(
			Controller\Report::class,
			'getUserReports',
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		return [
			'items' => $result ? $result->toArray() : [],
			'settings' => $this->getReportSettingsPayload(),
		];
	}
}
