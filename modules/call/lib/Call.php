<?php

namespace Bitrix\Call;

use Bitrix\Im\Call\Util;
use Bitrix\Im\Model\CallTable;
use Bitrix\Im\Model\CallUserTable;
use Bitrix\Im\V2\Call\CallFactory;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

class Call
{
	protected static function getCurrentUserId(): int
	{
		global $USER;

		return $USER->getId();
	}

	/**
	 * Gets list active calls of a user on portal
	 *
	 * @return array
	 */
	public static function getActiveCalls(): array
	{
		if (!Settings::isNewCallsEnabled())
		{
			return [];
		}

		$date = (new DateTime())->setTime(0, 0);
		$currentUserId = self::getCurrentUserId();

		$activeCalls = CallTable::query()
			->addSelect('*')
			->registerRuntimeField(
				'CALL_USER',
				new Reference(
					'CALL_USER',
					CallUserTable::class,
					Join::on('this.ID', 'ref.CALL_ID'),
					['join_type' => Join::TYPE_INNER]
				)
			)
			->whereIn('STATE', [\Bitrix\Im\Call\Call::STATE_NEW, \Bitrix\Im\Call\Call::STATE_INVITING])
			->where('START_DATE', '>=', $date)
			->where('CALL_USER.USER_ID', $currentUserId)
			->exec()
			->fetchAll()
		;

		return array_reduce($activeCalls, function ($result, $call) use ($currentUserId) {
			$callInstance = CallFactory::getCallInstance($call['PROVIDER'], $call);
			$callUsers = $callInstance->getUsers();

			$result[$call['ID']] = array_merge(
				$callInstance->toArray($currentUserId),
				[
					'CALL_TOKEN' => JwtCall::getCallToken($call['CHAT_ID']),
					'CONNECTION_DATA' => $callInstance->getConnectionData($currentUserId),
					'USERS' => $callUsers,
					'LOG_TOKEN' => $callInstance->getLogToken($currentUserId),
					'USER_DATA' => Util::getUsers($callUsers),
				]
			);

			return $result;
		}, []);
	}
}
