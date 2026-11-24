<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialservices;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialservices\UserTable;

final class UserQueryModifier
{
	public function injectRegistrationData(Query $query): Query
	{
		if (!Loader::includeModule('socialservices'))
		{
			return $query;
		}

		$query->registerRuntimeField(new Reference(
			'REGISTRATION_DATA_BINDING',
			UserTable::class,
			Join::on('this.ID', 'ref.USER_ID')
				->whereColumn('this.XML_ID', 'ref.XML_ID')
				->where('ref.EXTERNAL_AUTH_ID', \CSocServBitrix24Net::ID),
			['join_type' => 'LEFT'],
		));
		$query->addSelect('REGISTRATION_DATA_BINDING.LOGIN', 'REGISTRATION_DATA_LOGIN');
		$query->addSelect('REGISTRATION_DATA_BINDING.XML_ID', 'REGISTRATION_DATA_XML_ID');
		$query->addSelect('XML_ID', 'REGISTRATION_DATA_USER_XML_ID');

		return $query;
	}
}
