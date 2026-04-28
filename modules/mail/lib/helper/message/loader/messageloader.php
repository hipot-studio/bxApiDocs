<?php

namespace Bitrix\Mail\Helper\Message\Loader;

use Bitrix\Mail\Helper\Message;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mail\Helper\Dto\MessageContact;
use Bitrix\Main\Mail\Address;

class MessageLoader
{
	/**
	 * @param MessageFilter $filter
	 * @param PageNavigation $navigation Pagination object
	 * @return array Array of message items with aggregated BIND and CRM_ACTIVITY
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getMessageList(
		MessageFilter $filter,
		PageNavigation $navigation,
	): array
	{
		// +1 to fetch one extra record for "has next page" check
		$query = QueryBuilder::buildMailMessageListQuery(
			$filter->getArray(),
			$navigation->getLimit() + 1,
			$navigation->getOffset(),
		);

		$itemIds = array_column($query->fetchAll(), 'DISTINCT_ID');

		if (empty($itemIds))
		{
			return [];
		}

		$detailsQuery = QueryBuilder::buildWebMessagesDetailsQuery(
			$itemIds,
			$filter->getArray()
		);

		return self::aggregateMessages($detailsQuery->fetchAll());
	}

	public static function buildContactList($fieldValue): array
	{
		$addressList = Message::parseAddressList($fieldValue);

		$processedAddressesList = [];

		foreach ($addressList as $address)
		{
			$processedAddress = new Address($address);
			if ($processedAddress->validate())
			{
				$messageContact = new MessageContact();
				$messageContact->email = $processedAddress->getEmail();
				$messageContact->name = $processedAddress->getName();

				if (empty($messageContact->name))
				{
					$messageContact->name = $messageContact->email;
				}

				$processedAddressesList[] = $messageContact;
			}
		}

		return $processedAddressesList;
	}

	/**
	 * @param array $rows
	 * @return array
	 */
	private static function aggregateMessages(array $rows): array
	{
		$messageList = [];

		foreach($rows as $row)
		{
			$messageId = $row['MESSAGE_ID'];
			$row['BIND'] = (array)$row['BIND'];

			if(!array_key_exists($messageId, $messageList))
			{
				$row['CRM_ACTIVITY_OWNER'] = (array)@$row['CRM_ACTIVITY_OWNER'];
				$messageList[$messageId] = $row;
			}
			else
			{
				$messageList[$messageId]['BIND'] = array_unique(
					array_filter(
						array_merge(
							$messageList[$messageId]['BIND'],
							$row['BIND'],
						),
					),
				);

				$row['CRM_ACTIVITY_OWNER'] = (array)@$row['CRM_ACTIVITY_OWNER'];
				$messageList[$messageId]['CRM_ACTIVITY_OWNER'] = array_unique(
					array_filter(
						array_merge(
							$messageList[$messageId]['CRM_ACTIVITY_OWNER'],
							$row['CRM_ACTIVITY_OWNER'],
						),
					),
				);

				$messageList[$messageId]['IS_SEEN'] = max($messageList[$messageId]['IS_SEEN'], $row['IS_SEEN']);
			}
		}

		return array_values($messageList);
	}
}