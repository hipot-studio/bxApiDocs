<?php

namespace Bitrix\BIConnector\Integration\Calendar;

use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\DatasetFilter;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;
use Bitrix\BIConnector\DataSource\JoinSelection;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class Event extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'CALENDAR_EVENT_FIELD_';

	// Prefix of a location naming a room of the old type, as `Rooms\Util::parseLocation()` reads it. Such
	// a room is an element of an information block and keeps no booking of its own, so the prefix is the
	// only thing that names it. The module publishes no constant for it.
	private const LOCATION_PREFIX_LEGACY_ROOM = 'ECMR_';

	// Old spellings of the type of the calendar of the company. The calendar filters its own sections by
	// all three spellings at once (`Calendar\UserField\ResourceBooking`), and `CCalendarSect` still
	// accepts `calendar_company` as a type. The module publishes a constant for the current spelling only.
	private const CALENDAR_TYPE_COMPANY_LEGACY = ['company', 'calendar_company'];

	protected function getResultTableName(): string
	{
		return 'calendar_event';
	}

	public function getSqlTableAlias(): string
	{
		return 'CE';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_calendar_event';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('CALENDAR_EVENT_TABLE');
	}

	protected function getTableDescriptionFull(): string
	{
		return $this->getMessage('CALENDAR_EVENT_TABLE_DESCRIPTION_FULL', '');
	}

	/**
	 * @return Result
	 */
	protected function onBeforeEvent(): Result
	{
		$result = parent::onBeforeEvent();

		if (!Loader::includeModule('calendar'))
		{
			$result->addError(new Error('Module is not installed'));
		}

		return $result;
	}

	/**
	 * One row of the dataset is one participation in an event.
	 *
	 * A meeting is stored as a master row plus a copy per attendee, and the dataset hands over both:
	 * the pair of `PARENT_ID` and `IS_MASTER` is what tells them apart. A booked room keeps a shadow
	 * copy of its own, and it is the whitelist of the types of the calendar that keeps that one out.
	 *
	 * @return DatasetFilter
	 */
	protected function getFilter(): DatasetFilter
	{
		return new DatasetFilter(
			[
				'=DELETED' => 'N',
				'=ACTIVE' => 'Y',
				// The calendar of the company answers to three spellings of its type, and events of the
				// portals where an old one is stored belong to the dataset just as much.
				'@CAL_TYPE' => [
					Dictionary::CALENDAR_TYPE['user'],
					Dictionary::CALENDAR_TYPE['group'],
					Dictionary::CALENDAR_TYPE['company'],
					...self::CALENDAR_TYPE_COMPANY_LEGACY,
				],
			],
			[
				new StringField('DELETED'),
				new StringField('ACTIVE'),
				new StringField('CAL_TYPE'),
			],
		);
	}

	protected function getFields(): array
	{
		$helper = $this->getSqlHelper();

		$locationField = $this->getAliasFieldName('LOCATION');

		$recurrenceRuleField = $this->getAliasFieldName('RRULE');
		$recurrenceIdField = $this->getAliasFieldName('RECURRENCE_ID');

		$creatorJoin = $this->createJoin(
			'UCR',
			"INNER JOIN b_user UCR ON UCR.ID = {$this->getAliasFieldName('CREATED_BY')}",
			"LEFT JOIN b_user UCR ON UCR.ID = {$this->getAliasFieldName('CREATED_BY')}",
		);

		$hostJoin = $this->createJoin(
			'UHO',
			"INNER JOIN b_user UHO ON UHO.ID = {$this->getAliasFieldName('MEETING_HOST')}",
			"LEFT JOIN b_user UHO ON UHO.ID = {$this->getAliasFieldName('MEETING_HOST')}",
		);

		// The room is taken from the booking of the event, the way the calendar itself takes it
		// (`Rooms\Manager::createOccupancySnapshot()`), and not by parsing the location string: the
		// tail of that string is not guaranteed to be a number, and casting it to one breaks the whole
		// selection on PostgreSQL. A booked room keeps an event of its own tied to the event by
		// PARENT_ID, at most one per event, and releasing the room deletes that row rather than marking
		// it deleted.
		// The type condition belongs to the join on the sections: a legacy room shares the number space
		// with every other section. LEFT in both slots on purpose - the builder picks the inner variant
		// for a field that is only filtered on, and an event without a room must not drop out of the
		// selection either way.
		$locationCalendarType = $helper->forSql(Dictionary::CALENDAR_TYPE['location']);
		$roomJoinText =
			'LEFT JOIN b_calendar_event BOOKING'
			. " ON {$helper->quote('BOOKING.PARENT_ID')} = {$this->getAliasFieldName('ID')}"
			. " AND {$helper->quote('BOOKING.CAL_TYPE')} = '{$locationCalendarType}'"
			. " AND {$helper->quote('BOOKING.DELETED')} = 'N'"
			. ' LEFT JOIN b_calendar_section ROOM'
			. " ON {$helper->quote('ROOM.ID')} = {$helper->quote('BOOKING.SECTION_ID')}"
			. " AND {$helper->quote('ROOM.CAL_TYPE')} = '{$locationCalendarType}'"
		;
		// Both joins travel as one text: the builder keys the joins it prints by their text, so the pair
		// is printed once however many of the three fields of the room a request asks for.
		$bookingJoin = $this->createJoin('BOOKING', $roomJoinText, $roomJoinText);
		$roomJoin = $this->createJoin('ROOM', $roomJoinText, $roomJoinText);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new IntegerField('PARENT_ID')),
			(new StringField('IS_MASTER'))
				->setName(
					"CASE WHEN {$this->getAliasFieldName('PARENT_ID')} = {$this->getAliasFieldName('ID')} THEN 'Y' ELSE 'N' END"
				)
			,
			(new StringField('NAME')),
			(new StringField('EVENT_TYPE'))
				->setDictionary(
					[
						'' => $this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_REGULAR'),
						Dictionary::EVENT_TYPE['shared'] => $this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_SHARED'),
						Dictionary::EVENT_TYPE['shared_crm'] => $this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_SHARED_CRM'),
						Dictionary::EVENT_TYPE['shared_collab']
							=> $this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_SHARED_COLLAB'),
						Dictionary::EVENT_TYPE['collab'] => $this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_COLLAB'),
						Dictionary::EVENT_TYPE['booking'] => $this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_BOOKING'),
						Dictionary::EVENT_TYPE['call_sync'] => $this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_CALL_SYNC'),
						Dictionary::EVENT_TYPE['resource_booking']
							=> $this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_RESOURCE_BOOKING'),
					],
					// A regular event is stored both as an empty string and as NULL, and the dictionary
					// compares for equality, so NULL can only be named by the default branch.
					$this->getMessage('CALENDAR_EVENT_FIELD_EVENT_TYPE_VALUE_REGULAR'),
				)
			,
			(new StringField('CALENDAR_TYPE'))
				->setName($this->getCalendarTypeExpression())
			,
			(new IntegerField('OWNER_ID')),
			(new IntegerField('CREATED_BY_ID'))
				->setName($this->getAliasFieldName('CREATED_BY'))
			,
			(new StringField('CREATED_BY_NAME'))
				->setName($this->getUserNameExpression($this->getAliasFieldName('CREATED_BY'), $creatorJoin))
				->setJoin($creatorJoin)
			,
			// The same join as the name above: both fields read one user, and the builder prints the
			// join once however many of the two a request asks for.
			(new StringField('CREATED_BY'))
				->setName($this->getUserNameExpression($this->getAliasFieldName('CREATED_BY'), $creatorJoin, true))
				->setJoin($creatorJoin)
			,
			(new IntegerField('MEETING_HOST_ID'))
				->setName($this->getAliasFieldName('MEETING_HOST'))
			,
			(new StringField('MEETING_HOST_NAME'))
				->setName($this->getUserNameExpression($this->getAliasFieldName('MEETING_HOST'), $hostJoin))
				->setJoin($hostJoin)
			,
			// The same join as the name above, the way the three fields of the author share theirs.
			(new StringField('MEETING_HOST'))
				->setName($this->getUserNameExpression($this->getAliasFieldName('MEETING_HOST'), $hostJoin, true))
				->setJoin($hostJoin)
			,
			// The column is written as a bool cast, so it holds '1', '0' or NULL; 'Y' stays accepted
			// because the field validator has always allowed it.
			(new StringField('IS_MEETING'))
				->setName(
					"CASE WHEN {$this->getAliasFieldName('IS_MEETING')} IN ('Y', '1') THEN 'Y' ELSE 'N' END"
				)
			,
			(new StringField('MEETING_STATUS'))
				->setDictionary(
					[
						Dictionary::MEETING_STATUS['Host'] => $this->getMessage('CALENDAR_EVENT_FIELD_MEETING_STATUS_VALUE_HOST'),
						Dictionary::MEETING_STATUS['Yes'] => $this->getMessage('CALENDAR_EVENT_FIELD_MEETING_STATUS_VALUE_YES'),
						Dictionary::MEETING_STATUS['No'] => $this->getMessage('CALENDAR_EVENT_FIELD_MEETING_STATUS_VALUE_NO'),
						Dictionary::MEETING_STATUS['Question'] => $this->getMessage('CALENDAR_EVENT_FIELD_MEETING_STATUS_VALUE_QUESTION'),
					],
					$this->getMessage('CALENDAR_EVENT_FIELD_MEETING_STATUS_VALUE_DEFAULT'),
				)
			,
			// The first field of a date type is the one the pipeline takes for the period when the client
			// names no column, so DATE_FROM comes before DATE_CREATE and TIMESTAMP_X.
			(new DateTimeField('DATE_FROM')),
			(new DateTimeField('DATE_TO')),
			// The zone of the event itself, the one the stored start and end times are read in. The
			// column is nullable and an event with no time of its own may hold an empty string there,
			// so the empty value is reported as a missing one and not as a category of the chart.
			(new StringField('TIMEZONE_FROM'))
				->setName("NULLIF({$this->getAliasFieldName('TZ_FROM')}, '')")
			,
			(new IntegerField('TIMEZONE_OFFSET'))
				->setName($this->getAliasFieldName('TZ_OFFSET_FROM'))
			,
			(new IntegerField('DURATION'))
				->setName($this->getAliasFieldName('DT_LENGTH'))
				->setMetric()
			,
			// The column is nullable and holds no default of its own, so the flag is normalized here rather
			// than trusted to the writing side, as the four neighbouring flags are.
			(new StringField('IS_FULL_DAY'))
				->setName(
					"CASE WHEN {$this->getAliasFieldName('DT_SKIP_TIME')} = 'Y' THEN 'Y' ELSE 'N' END"
				)
			,
			// An event without a repeat carries an empty rule, not NULL, so both states are checked.
			(new StringField('IS_RECURRING'))
				->setName(
					"CASE WHEN {$recurrenceRuleField} IS NOT NULL AND {$recurrenceRuleField} <> ''"
					. " THEN 'Y' ELSE 'N' END"
				)
			,
			(new StringField('RECURRENCE_RULE'))
				->setName($this->getRecurrenceFrequencyExpression($recurrenceRuleField))
			,
			(new StringField('IS_RECURRENCE_EXCEPTION'))
				->setName(
					"CASE WHEN {$recurrenceIdField} IS NOT NULL AND {$recurrenceIdField} > 0"
					. " THEN 'Y' ELSE 'N' END"
				)
			,
			(new StringField('ACCESSIBILITY'))
				->setDictionary(
					[
						'busy' => $this->getMessage('CALENDAR_EVENT_FIELD_ACCESSIBILITY_VALUE_BUSY'),
						'quest' => $this->getMessage('CALENDAR_EVENT_FIELD_ACCESSIBILITY_VALUE_QUEST'),
						'free' => $this->getMessage('CALENDAR_EVENT_FIELD_ACCESSIBILITY_VALUE_FREE'),
						'absent' => $this->getMessage('CALENDAR_EVENT_FIELD_ACCESSIBILITY_VALUE_ABSENT'),
					],
					$this->getMessage('CALENDAR_EVENT_FIELD_ACCESSIBILITY_VALUE_DEFAULT'),
				)
			,
			(new StringField('IMPORTANCE'))
				->setDictionary(
					[
						'normal' => $this->getMessage('CALENDAR_EVENT_FIELD_IMPORTANCE_VALUE_NORMAL'),
						'high' => $this->getMessage('CALENDAR_EVENT_FIELD_IMPORTANCE_VALUE_HIGH'),
						'low' => $this->getMessage('CALENDAR_EVENT_FIELD_IMPORTANCE_VALUE_LOW'),
					],
					$this->getMessage('CALENDAR_EVENT_FIELD_IMPORTANCE_VALUE_DEFAULT'),
				)
			,
			(new StringField('IS_PRIVATE'))
				->setName(
					"CASE WHEN {$this->getAliasFieldName('PRIVATE_EVENT')} IN ('Y', '1') THEN 'Y' ELSE 'N' END"
				)
			,
			(new StringField('LOCATION')),
			(new StringField('LOCATION_TYPE'))
				->setName($this->getLocationTypeExpression($locationField, $bookingJoin->getJoinFieldName('ID')))
				->setJoin($bookingJoin)
			,
			(new IntegerField('LOCATION_ROOM_ID'))
				->setName($bookingJoin->getJoinFieldName('SECTION_ID'))
				->setJoin($bookingJoin)
			,
			(new StringField('LOCATION_ROOM_NAME'))
				->setName($roomJoin->getJoinFieldName('NAME'))
				->setJoin($roomJoin)
			,
			(new DateTimeField('DATE_CREATE')),
			(new DateTimeField('TIMESTAMP_X')),
		];
	}

	/**
	 * Kind of place the location names.
	 *
	 * A room of the calendar is the one the booking was found for, the same answer the calendar gives
	 * itself; free text that merely starts like a location code is text and nothing more. A room of the
	 * old type has no booking to be found, so it is still recognised by its prefix - matched on a leading
	 * substring rather than with LIKE, where `_` is a wildcard escaped differently by each dialect.
	 *
	 * @param string $locationField Location column of the event.
	 * @param string $bookingIdField Identifier of the booking of the room, NULL when there is none.
	 *
	 * @return string
	 */
	private function getLocationTypeExpression(string $locationField, string $bookingIdField): string
	{
		$helper = $this->getSqlHelper();

		$legacyPrefix = $helper->forSql(self::LOCATION_PREFIX_LEGACY_ROOM);
		$legacyPrefixSegment = $helper->getSubstrFunction($locationField, 1, strlen(self::LOCATION_PREFIX_LEGACY_ROOM));

		$notSet = $helper->forSql($this->getMessage('CALENDAR_EVENT_FIELD_LOCATION_TYPE_VALUE_NOT_SET'));
		$room = $helper->forSql($this->getMessage('CALENDAR_EVENT_FIELD_LOCATION_TYPE_VALUE_ROOM'));
		$legacyRoom = $helper->forSql($this->getMessage('CALENDAR_EVENT_FIELD_LOCATION_TYPE_VALUE_LEGACY_ROOM'));
		$text = $helper->forSql($this->getMessage('CALENDAR_EVENT_FIELD_LOCATION_TYPE_VALUE_TEXT'));

		return 'CASE'
			. " WHEN {$locationField} IS NULL OR {$locationField} = '' THEN '{$notSet}'"
			. " WHEN {$bookingIdField} IS NOT NULL THEN '{$room}'"
			. " WHEN {$legacyPrefixSegment} = '{$legacyPrefix}' THEN '{$legacyRoom}'"
			. " ELSE '{$text}' END"
		;
	}

	/**
	 * How often the series repeats.
	 *
	 * The rule is packed as unordered `KEY=VALUE;` pairs, so the frequency is found by looking for its
	 * pair anywhere in the string rather than by taking a segment at a fixed position.
	 *
	 * @param string $recurrenceRuleField Rule column of the event.
	 *
	 * @return string
	 */
	private function getRecurrenceFrequencyExpression(string $recurrenceRuleField): string
	{
		$helper = $this->getSqlHelper();

		// The closed list `CCalendarEvent::ParseRRULE()` accepts; anything else leaves the field empty.
		$frequencies = [
			'DAILY' => 'CALENDAR_EVENT_FIELD_RECURRENCE_RULE_VALUE_DAILY',
			'WEEKLY' => 'CALENDAR_EVENT_FIELD_RECURRENCE_RULE_VALUE_WEEKLY',
			'MONTHLY' => 'CALENDAR_EVENT_FIELD_RECURRENCE_RULE_VALUE_MONTHLY',
			'YEARLY' => 'CALENDAR_EVENT_FIELD_RECURRENCE_RULE_VALUE_YEARLY',
		];

		$cases = [];
		foreach ($frequencies as $frequency => $phraseCode)
		{
			$cases[] = "WHEN {$recurrenceRuleField} LIKE '%FREQ=" . $helper->forSql($frequency) . "%'"
				. " THEN '" . $helper->forSql($this->getMessage($phraseCode)) . "'"
			;
		}

		return 'CASE ' . implode(' ', $cases) . ' ELSE NULL END';
	}

	/**
	 * Full name of the user an id column points at, optionally headed by the id itself.
	 *
	 * The guard on the id is what keeps an event with no organizer out: without it the row would carry
	 * a string of spaces instead of an empty value.
	 *
	 * @param string $userIdField Column holding the user id.
	 * @param JoinSelection $join Join on `b_user` by that column.
	 * @param bool $withId Head the value with the id in brackets, the way the datasets of CRM spell it.
	 *
	 * @return string
	 */
	private function getUserNameExpression(string $userIdField, JoinSelection $join, bool $withId = false): string
	{
		$parts = [
			"nullif({$join->getJoinFieldName('NAME')}, '')",
			"nullif({$join->getJoinFieldName('LAST_NAME')}, '')",
		];

		if ($withId)
		{
			// A user deleted from the portal keeps the number: the name is gone, and the number is still
			// what tells those events from the events of another author who lost a name as well.
			array_unshift($parts, $this->getSqlHelper()->getConcatFunction("'['", $userIdField, "']'"));
		}

		$value = "concat_ws(' ', " . implode(', ', $parts) . ')';

		// The outer nullif is what an event pointing at a user that no longer exists needs: the join
		// matches nothing, every part is null, and concat_ws of nothing is an empty string rather than
		// nothing at all.
		return "
			CASE WHEN {$userIdField} > 0 THEN
				nullif({$value}, '')
			ELSE NULL END"
		;
	}

	/**
	 * The dictionary of a field is compiled over the column named after the field code, so a field
	 * reading a differently named column spells the CASE out itself.
	 *
	 * @return string
	 */
	private function getCalendarTypeExpression(): string
	{
		$helper = $this->getSqlHelper();
		$column = $this->getAliasFieldName('CAL_TYPE');

		$company = $this->getMessage('CALENDAR_EVENT_FIELD_CALENDAR_TYPE_VALUE_COMPANY');

		$dictionary = [
			Dictionary::CALENDAR_TYPE['user'] => $this->getMessage('CALENDAR_EVENT_FIELD_CALENDAR_TYPE_VALUE_USER'),
			Dictionary::CALENDAR_TYPE['group'] => $this->getMessage('CALENDAR_EVENT_FIELD_CALENDAR_TYPE_VALUE_GROUP'),
			Dictionary::CALENDAR_TYPE['company'] => $company,
		];

		// The old spellings name the same calendar, so they name the same category of the chart.
		foreach (self::CALENDAR_TYPE_COMPANY_LEGACY as $legacyCode)
		{
			$dictionary[$legacyCode] = $company;
		}

		$cases = [];
		foreach ($dictionary as $code => $phrase)
		{
			$cases[] = "WHEN {$column} = '{$helper->forSql($code)}' THEN '{$helper->forSql($phrase)}'";
		}

		$defaultCase = $helper->forSql($this->getMessage('CALENDAR_EVENT_FIELD_CALENDAR_TYPE_VALUE_DEFAULT'));

		return 'CASE ' . implode(' ', $cases) . " ELSE '{$defaultCase}' END";
	}
}
