<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base;

use Bitrix\Main\Context;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type;

class DateFieldAssembler extends FieldAssembler
{
	public const TIME_FORMAT_SHORT = 'short';
	public const TIME_FORMAT_LONG = 'long';

	private ?string $timeFormat;

	public function __construct(
		array $columnIds,
		?Settings $settings = null,
		?string $timeFormat = null,
	)
	{
		$this->timeFormat = $timeFormat;

		parent::__construct($columnIds, $settings);
	}

	protected function prepareColumn($value)
	{
		if (!$value instanceof Type\DateTime)
		{
			return $value;
		}

		$userDate = $value->toUserTime();
		$timestamp = (int)$userDate->getTimestamp();
		$now = time() + \CTimeZone::getOffset();
		$userCulture = Context::getCurrent()?->getCulture();

		if (
			$this->timeFormat === null
			&& $now - $timestamp < 60
		)
		{
			$readableDate = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_GRID_DATE_NOW');
		}
		else
		{
			$isCurrentYear = (date('Y') === date('Y', $timestamp));
			$dateFormat =
				$isCurrentYear
					? $userCulture?->getDayMonthFormat()
					: $userCulture?->getLongDateFormat()
			;

			if ($this->timeFormat !== null)
			{
				$dateFormat = [
					'today' => 'today',
					'yesterday' => 'yesterday',
					'' => $dateFormat ?? '',
				];
			}
			else
			{
				$nowDate = (new Type\DateTime())->toUserTime()->setTime(0, 0);
				$diff = $nowDate->getDiff($value);
				if ($diff->days < 2)
				{
					$dateFormat = 'x';
				}
			}

			$readableDate = formatDate($dateFormat, $timestamp, $now);
		}

		$timeHtml = '';
		if ($this->timeFormat !== null)
		{
			$timeFormat =
				$this->timeFormat === self::TIME_FORMAT_LONG
					? $userCulture?->getLongTimeFormat()
					: $userCulture?->getShortTimeFormat()
			;

			$readableTime = $userDate->format($timeFormat ?? 'H:i');

			$timeHtml = <<<HTML
				<span class="biconnector-grid-date-time__time">{$readableTime}</span>
			HTML;
		}

		$fullDate = formatDate('FULL', $timestamp, $now);

		return <<<HTML
			<span
				class="biconnector-grid-date-time"
				data-hint="{$fullDate}"
				data-hint-no-icon
				data-hint-interactivity
				data-hint-center
			>
				<span class="biconnector-grid-date-time__date">{$readableDate}</span>
			{$timeHtml}
			</span>
		HTML;
	}
}