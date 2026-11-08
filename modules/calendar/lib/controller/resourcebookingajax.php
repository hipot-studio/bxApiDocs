<?php
namespace Bitrix\Calendar\Controller;

use Bitrix\Calendar\Internal\Service\PublicBooking\PublicBookingFormDataService;
use Bitrix\Calendar\Internal\Service\PublicBooking\RejectedRequestLogger;
use Bitrix\Calendar\Internal\Service\PublicBooking\Source\SourceDescriptor;
use Bitrix\Calendar\UserField\ResourceBooking;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use CCalendar;

Loc::loadMessages(__FILE__);

/**
 * Class ResourceBookingAjax
 */

class ResourceBookingAjax extends \Bitrix\Main\Engine\Controller
{
	private const MAX_WINDOW_DAYS = 63;

	public function configureActions()
	{
		return [
			'getFillFormData' => [
				'-prefilters' => [
					ActionFilter\Authentication::class,
					ActionFilter\Csrf::class
				],
				'+postfilters' => [
					new ActionFilter\Cors()
				],
			],
		];
	}

	public function getPlannerDataAction()
	{
		$request = $this->getRequest();

		if (Loader::includeModule('intranet') && !\Bitrix\Intranet\Util::isIntranetUser())
		{
			return [];
		}

		return \CCalendarPlanner::prepareData(array(
			'user_id' => \CCalendar::getCurUserId(),
			'codes' => $request->getPost('codes'),
			'resources' => $request->getPost('resources'),
			'date_from' => \CCalendar::date(\CCalendar::timestamp($request->getPost('from')), false),
			'date_to' => \CCalendar::date(\CCalendar::timestamp($request->getPost('to')), false),
			'timezone' => \CCalendar::getUserTimezoneName(\CCalendar::getCurUserId()),
			'skipEntryList' => $request->getPost('currentEventList')
		));
	}

	public function getDefaultUserfieldSettingsAction()
	{
		return ResourceBooking::prepareSettings();
	}

	public function getBitrix24LimitationAction()
	{
		return ResourceBooking::getBitrx24Limitation();
	}

	public function getUserSelectorDataAction()
	{
		$request = $this->getRequest();

		$selectedUserList = [];
		if (!empty($request['selectedUserList']) && is_array($request['selectedUserList']))
		{
			$selectedUserList = $request['selectedUserList'];
		}

		return \CCalendar::getSocNetDestination(false, array(), $selectedUserList);
	}

	public function getFieldParamsAction()
	{
		$request = $this->getRequest();
		return ResourceBooking::getUserFieldByFieldName($request['fieldname'], $request['selectedUsers']);
	}

	public function getFillFormDataAction()
	{
		$request = $this->getRequest();

		if (isset($request['settingsData']))
		{
			$this->logRejectedRequest('legacy request: settingsData present');

			return [];
		}

		$window = [
			'from' => $this->requestString($request, 'from'),
			'to' => $this->requestString($request, 'to'),
		];
		// The public form always sends both bounds. A one-sided window would let the downstream
		// default derive the missing bound (now / from+60d), which a crafted request can stretch
		// into a huge availability span on this anonymous endpoint. Both bounds empty is fine
		// (downstream uses now..+60d).
		if (($window['from'] === '') !== ($window['to'] === ''))
		{
			return [];
		}
		if (
			!$this->isWindowDateParseable($window['from'])
			|| !$this->isWindowDateParseable($window['to'])
			|| !$this->isWindowSpanAllowed($window['from'], $window['to'])
		)
		{
			return [];
		}

		$descriptor = new SourceDescriptor(
			$this->requestString($request, 'sourceType'),
			[
				'formId' => $this->requestInt($request, 'formId'),
				'sec' => $this->requestString($request, 'sec'),
				'fieldCode' => $this->requestString($request, 'fieldCode'),
			],
		);

		/** @var PublicBookingFormDataService $service */
		$service = ServiceLocator::getInstance()->get(PublicBookingFormDataService::class);

		return $service->getFormData($descriptor, $window);
	}

	private function isWindowDateParseable(string $value): bool
	{
		if ($value === '')
		{
			return true;
		}

		return (int)CCalendar::Timestamp($value) > 0;
	}

	// The form navigates with a sliding ~2-month window, so cap the span relative to `from`
	// rather than an absolute [today..today+N] range (the latter rejected legitimate forward
	// navigation). Keeps recurrence expansion bounded on this anonymous endpoint.
	private function isWindowSpanAllowed(string $from, string $to): bool
	{
		if ($from === '' || $to === '')
		{
			return true;
		}

		$fromTimestamp = (int)CCalendar::Timestamp($from);
		$toTimestamp = (int)CCalendar::Timestamp($to);
		$maxTo = $fromTimestamp + CCalendar::DAY_LENGTH * self::MAX_WINDOW_DAYS;

		return $toTimestamp >= $fromTimestamp && $toTimestamp <= $maxTo;
	}

	// Coerces a request value to a string, ignoring non-scalar (e.g. array) input. A crafted
	// `field[]=x` on this anonymous endpoint would otherwise raise an "Array to string conversion"
	// warning on every hit, and those warnings bypass the logger quota and flood the PHP log.
	private function requestString(\Bitrix\Main\Request $request, string $key): string
	{
		$value = $request[$key];

		return is_scalar($value) ? (string)$value : '';
	}

	private function requestInt(\Bitrix\Main\Request $request, string $key): int
	{
		$value = $request[$key];

		return is_scalar($value) ? (int)$value : 0;
	}

	private function logRejectedRequest(string $reason): void
	{
		$request = $this->getRequest();

		(new RejectedRequestLogger())->log($reason, [
			'sourceType' => $this->requestString($request, 'sourceType'),
			'formId' => $this->requestInt($request, 'formId'),
			'fieldCode' => $this->requestString($request, 'fieldCode'),
			'sec' => $this->requestString($request, 'sec'),
			'from' => $this->requestString($request, 'from'),
			'to' => $this->requestString($request, 'to'),
			'hasSettingsData' => isset($request['settingsData']),
		]);
	}
}
