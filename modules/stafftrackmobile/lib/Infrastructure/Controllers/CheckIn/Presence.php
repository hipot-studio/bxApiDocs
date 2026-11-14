<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers\CheckIn;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Mobile\Provider\CommonUserDto;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\StaffTrack\Public\Provider\Presence\PresenceListQuery;
use Bitrix\StaffTrack\Public\Provider\Presence\PresenceScopeResolver;
use Bitrix\StaffTrack\Public\Provider\Presence\TeamPresenceProvider;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\CheckInToolEnabled;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\CheckInAvailableByRegionFilter;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\IntranetOnlyFilter;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\NewCheckInEnabledFilter;
use Bitrix\StaffTrackMobile\Infrastructure\Controllers\Base;
use Bitrix\StaffTrackMobile\Public\Features\CheckInV3StaffSelectorFeature;
use Bitrix\StaffTrackMobile\Public\Features\PresenceListFeature;

/**
 * The presence of the team on one date, a portion of one tab at a time, for the widget over the map of the check-in
 * section.
 *
 * It takes the prefilters and the selector of the map. Refusals stay error codes of the action rather than
 * prefilters: the client tells a retry from a hidden widget by the code, and a prefilter answers a plain 403.
 */
final class Presence extends Base
{
	private ?TeamPresenceProvider $presenceProvider = null;

	public function __construct(?Request $request = null, ?TeamPresenceProvider $presenceProvider = null)
	{
		parent::__construct($request);

		$this->presenceProvider = $presenceProvider;
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new IntranetOnlyFilter(),
			new NewCheckInEnabledFilter(),
			new CheckInAvailableByRegionFilter(),
			new CloseSession(),
		];
	}

	/**
	 * One portion of one tab of the team on one date, or one error code (`presence.list.v2`). The rollout flag closes
	 * the action itself, so nothing of the team is read while it is off.
	 *
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Presence.getList
	 * @param int $timestamp moment of the date being asked about, zero for today
	 * @param array $entities selector of the composition, the one the map takes
	 * @param string|null $tab code of the tab, null for the default one of the date
	 * @param string $query search over the whole team, empty for none
	 * @param string|null $cursor `nextCursor` of the portion before, null for the first one
	 * @param int $limit rows of the portion at the most
	 * @param mixed $withMapPoints the people the map draws are counted: the card asks for them, the sheet does not.
	 *     Untyped, so the binder hands the flag over as it was sent: a bool takes the word `false` for true
	 * @param mixed $withIndexAge the answer says how old the index of the team it was cut from is: the client that asks
	 *     keeps the card for what is left of the minute of that index, so the card is cut from it too. Untyped as above
	 * @return array|null
	 */
	#[CheckInToolEnabled]
	public function getListAction(
		int $timestamp = 0,
		array $entities = [],
		?string $tab = null,
		string $query = '',
		?string $cursor = null,
		int $limit = 30,
		$withMapPoints = false,
		$withIndexAge = false,
	): ?array
	{
		if (!(new PresenceListFeature())->isEnabled())
		{
			$this->addError(new Error(
				'The presence list is switched off on the portal',
				PresenceScopeResolver::ERROR_UNAVAILABLE,
			));

			return null;
		}

		$result = $this->getPresenceProvider()->getPresencePortion(
			(int)$this->getCurrentUser()?->getId(),
			new PresenceListQuery(
				$timestamp,
				$this->narrowedBy($entities),
				$tab,
				$query,
				$cursor,
				$limit,
				self::isAsked($withMapPoints),
				self::isAsked($withIndexAge),
			),
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();
		$items = $data['items'] ?? [];
		$data['users'] = self::drawnFieldsOf(
			UserRepository::getByIds(self::userIdsOf($items)),
			self::noConsentUserIdsOf($items),
		);

		return $data;
	}

	/**
	 * Built on demand: the controller is created for hits that never reach the action.
	 */
	private function getPresenceProvider(): TeamPresenceProvider
	{
		return $this->presenceProvider ??= new TeamPresenceProvider();
	}

	/**
	 * A flag of the request as a form spells it: `true`, `1`, `on` and `yes` ask, whatever the case of the letters,
	 * and a boolean of JSON is itself. Anything else asks nothing - `false`, `0`, `off`, `no` and an empty value, as
	 * well as a value that spells no flag at all, which leaves the default of the action.
	 */
	private static function isAsked(mixed $flag): bool
	{
		return filter_var($flag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
	}

	/**
	 * The selector is taken only where the map takes it, so the list and the map show the same people.
	 */
	private function narrowedBy(array $entities): array
	{
		return (new CheckInV3StaffSelectorFeature())->isEnabled() ? $entities : [];
	}

	/**
	 * What a row of the list draws of its employee, and nothing more: the people of a portion travel to the device of
	 * the manager, and their contacts, logins and administrator flags have no row to be drawn in. The gender picks
	 * the form of the no-consent mark and is drawn nowhere else, so only an employee whose row carries the mark has
	 * it sent; everyone else gets null.
	 *
	 * @param CommonUserDto[] $users
	 * @param array<int, true> $noConsentUserIds
	 * @return array<int, array{
	 *     id: int,
	 *     name: ?string,
	 *     lastName: ?string,
	 *     fullName: ?string,
	 *     workPosition: ?string,
	 *     avatarSize100: ?string,
	 *     personalGender: 'M'|'F'|null,
	 * }>
	 */
	private static function drawnFieldsOf(array $users, array $noConsentUserIds): array
	{
		return array_map(
			static fn (CommonUserDto $user): array => [
				'id' => $user->id,
				'name' => $user->name,
				'lastName' => $user->lastName,
				'fullName' => $user->fullName,
				'workPosition' => $user->workPosition,
				'avatarSize100' => $user->avatarSize100,
				'personalGender' => isset($noConsentUserIds[$user->id]) ? self::genderOf($user) : null,
			],
			$users,
		);
	}

	/**
	 * The core keeps an empty string for an employee who has not given one.
	 *
	 * @return 'M'|'F'|null
	 */
	private static function genderOf(CommonUserDto $user): ?string
	{
		return in_array($user->personalGender, ['M', 'F'], true) ? $user->personalGender : null;
	}

	/**
	 * @param array<int, array{userId: int}> $items the rows of the portion
	 * @return int[]
	 */
	private static function userIdsOf(array $items): array
	{
		return array_column($items, 'userId');
	}

	/**
	 * @param array<int, array{userId: int, noConsent: bool}> $items the rows of the portion
	 * @return array<int, true>
	 */
	private static function noConsentUserIdsOf(array $items): array
	{
		$userIds = [];
		foreach ($items as $item)
		{
			if (($item['noConsent'] ?? false) === true)
			{
				$userIds[(int)$item['userId']] = true;
			}
		}

		return $userIds;
	}
}
