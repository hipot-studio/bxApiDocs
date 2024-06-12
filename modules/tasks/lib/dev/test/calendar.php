<?

namespace Bitrix\Tasks\Dev\Test;

class Calendar
{
	public function findWorkTimeTest()
	{
		$date = 1465024969;

		$dateInst = \Bitrix\Tasks\Util\Type\DateTime::createFromUserTimeGmt(\Bitrix\Tasks\UI::formatDateTime($date));
		$calendar = new \Bitrix\Tasks\Util\Calendar();

		if(!$calendar->isWorkTime($dateInst))
		{
			static::printDebugTime('Not a worktime: ', $date);

			$cwt = $calendar->getClosestWorkTime($dateInst); // get closest time in UTC
			$cwt = $cwt->convertToLocalTime(); // change timezone to server timezone

			$date = $cwt->getTimestamp(); // set server timestamp

			static::printDebugTime('Closest worktime: ', $date);
		}
	}

	private static function printDebugTime($l, $t)
	{
		print_r($l.\Bitrix\Tasks\UI::formatDateTime($t).PHP_EOL);
	}
}