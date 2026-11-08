<?php

namespace Bitrix\Mail;

use Bitrix\Main\Entity;
use Bitrix\Main\Localization;
use Bitrix\Main\ORM;

Localization\Loc::loadMessages(__FILE__);

/**
 * Class MailServicesTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailServices_Query query()
 * @method static EO_MailServices_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailServices_Result getById($id)
 * @method static EO_MailServices_Result getList(array $parameters = [])
 * @method static EO_MailServices_Entity getEntity()
 * @method static \Bitrix\Mail\EO_MailServices createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\EO_MailServices_Collection createCollection()
 * @method static \Bitrix\Mail\EO_MailServices wakeUpObject($row)
 * @method static \Bitrix\Mail\EO_MailServices_Collection wakeUpCollection($rows)
 */
class MailServicesTable extends Entity\DataManager
{
	/**
	 * The bit of FLAGS marking a service whose connection credentials are issued outside the portal.
	 *
	 * Declared here rather than taken from the legacy CMail, although that class holds the two flags
	 * of the domain mail next to it: this table is read from an autoloaded class on paths where the
	 * legacy half of the module has not been included, and a constant of a class that is not there
	 * is a fatal error rather than a false. The legacy constant stays as it is, for the code that
	 * already reads the flags of a domain beside it.
	 */
	public const FLAG_EXTERNAL_CREDENTIALS = 4;


	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_mailservices';
	}

	public static function checkFields(Entity\Result $result, $primary, array $data)
	{
		parent::checkFields($result, $primary, $data);

		if (isset($data['SITE_ID']))
		{
			$selectResult = \Bitrix\Main\SiteTable::getByPrimary($data['SITE_ID']);
			if (!$selectResult->fetch())
			{
				$field = static::getEntity()->getField('SITE_ID');
				$result->addError(new Entity\FieldError(
					$field,
					Localization\Loc::getMessage('MAIN_ENTITY_FIELD_INVALID', array('#FIELD_TITLE#' => $field->getTitle())),
					Entity\FieldError::INVALID_VALUE
				));
			}
		}

		if (!empty($data['ICON']))
		{
			if (!is_scalar($data['ICON']) || !preg_match('/[0-9]+/', $data['ICON']))
			{
				$field = static::getEntity()->getField('ICON');
				$result->addError(new Entity\FieldError(
					$field,
					Localization\Loc::getMessage('MAIN_ENTITY_FIELD_INVALID', array('#FIELD_TITLE#' => $field->getTitle())),
					Entity\FieldError::INVALID_VALUE
				));
			}
		}

		return $result;
	}

	public static function add(array $data)
	{
		if (isset($data['ICON']) && is_array($data['ICON']))
		{
			$iconError = $data['ICON']['name'] ? \CFile::checkImageFile($data['ICON']) : null;
			if (is_null($iconError))
			{
				$data['ICON']['MODULE_ID'] = 'mail';

				\CFile::saveForDB($data, 'ICON', 'mail/mailservices/icon');
			}
		}

		return parent::add($data);
	}

	public static function update($primary, array $data): Entity\UpdateResult
	{
		if (empty($data))
			return new Entity\UpdateResult();

		$serviceForUpdate = static::getByPrimary(
			$primary,
			array(
				'select' => array(
					'ID', 'SITE_ID', 'ACTIVE', 'SERVICE_TYPE',
				),
			)
		)->fetch();
		if (!$serviceForUpdate)
		{
			$updateResult = new Entity\UpdateResult();
			$updateResult->addError(new Entity\EntityError(Localization\Loc::getMessage('mail_mailservice_not_found')));

			return $updateResult;
		}

		if (isset($data['ICON']) && is_array($data['ICON']))
		{
			$iconError = $data['ICON']['name'] ? \CFile::checkImageFile($data['ICON']) : null;
			if (is_null($iconError))
			{
				$data['ICON']['MODULE_ID'] = 'mail';

				\CFile::saveForDB($data, 'ICON', 'mail/mailservices/icon');
			}
		}

		$updateResult = parent::update($primary, $data);

		if ($updateResult->isSuccess())
		{
			$serviceId = is_array($primary) ? $primary['ID'] : $primary;

			$isSiteChanged = isset($data['SITE_ID']) && $data['SITE_ID'] != $serviceForUpdate['SITE_ID'];
			$isDeactivated = isset($data['ACTIVE']) && $data['ACTIVE'] == 'N' && $serviceForUpdate['ACTIVE'] == 'Y';
			if (($isSiteChanged || $isDeactivated) && $serviceForUpdate['SERVICE_TYPE'] == 'imap')
			{
				$emptyService = static::findEmptyService($serviceForUpdate['SITE_ID'], true);
			}

			if ($isSiteChanged || $isDeactivated && $emptyService)
			{
				$mbData = $emptyService
					? array('SERVICE_ID' => $emptyService['ID'])
					: array('ACTIVE' => 'N', 'SERVICE_ID' => 0);
			}
			else
			{
				$mbData = array();
				foreach ($data as $key => $value)
				{
					if (empty($value))
						continue;

					switch ($key)
					{
						case 'ACTIVE':
						case 'NAME':
						case 'SERVER':
						case 'PORT':
						case 'LINK':
							$mbData[$key] = $value;
							break;
						case 'ENCRYPTION':
							$mbData['USE_TLS'] = $value;
							break;
					}
				}
			}

			$selectResult = \CMailbox::getList(array(), array('SERVICE_ID' => $serviceId));
			while ($mailbox = $selectResult->fetch())
				\CMailbox::update($mailbox['ID'], $mbData);
		}

		return $updateResult;
	}

	public static function getIconSrc($serviceName, $iconId = null)
	{
		if ($iconId)
		{
			$icon = \CFile::getFileArray($iconId);

			return $icon['SRC'];
		}
		else
		{
			$icons = array(
				'bitrix24'	=> '/bitrix/images/mail/mailservice-icon/' . Localization\Loc::getMessage('mail_mailservice_bitrix24_icon'),
				'mailhost'	=> '/bitrix/images/mail/mailservice-icon/post-mailhost-icon.svg',
				'gmail'	=> '/bitrix/images/mail/mailservice-icon/post-gmail-icon.svg',
				'icloud'	=> '/bitrix/images/mail/mailservice-icon/post-icloud-icon.svg',
				'outlook.com'	=> '/bitrix/images/mail/mailservice-icon/post-outlook-icon.svg',
				'office365'	=> '/bitrix/images/mail/mailservice-icon/post-office360-icon.svg',
				'yahoo'	=> '/bitrix/images/mail/mailservice-icon/post-yahoo-icon.svg',
				'aol'	=> '/bitrix/images/mail/mailservice-icon/post-aol-icon.svg',
				'yandex'	=> '/bitrix/images/mail/mailservice-icon/post-yandex-icon.svg',
				'mail.ru'	=> '/bitrix/images/mail/mailservice-icon/post-mail-icon.svg',
				'ukr.net'	=> '/bitrix/images/mail/mailservice-icon/post-ukrnet-icon.svg',
				'exchange'	=> '/bitrix/images/mail/mailservice-icon/post-imap-icon.svg',
				'exchangeOnline'	=> '/bitrix/images/mail/mailservice-icon/post-exchange-icon.svg',
				'other'	=> '/bitrix/images/mail/mailservice-icon/post-imap-icon.svg',
			);

			if ($icons[$serviceName])
				return $icons[$serviceName];
		}

		return null;
	}

	/**
	 * The service a mailbox of a deleted or deactivated one is re-hung onto: an active service of the
	 * same site carrying no connection data of its own, so that the mailbox keeps its own addresses.
	 *
	 * A service whose credentials come from outside is never that one. It has no addresses to hand a
	 * mailbox of somebody else, and a mailbox that landed on it would lose the right to edit its own -
	 * the very right it has to keep. The mark is asked of the query and not of the answer: the pick
	 * takes a single row, so dropping it afterwards would report "no empty service" while the next
	 * suitable row stands right behind it.
	 *
	 * @param bool $imapOnly Whether the mailboxes to re-hang are those of an ordinary IMAP service.
	 * @return array{ID: int, NAME: string}|null
	 */
	private static function findEmptyService($siteId, bool $imapOnly): ?array
	{
		$filter = array(
			'=SITE_ID'    => $siteId,
			'ACTIVE'      => 'Y',
			'=SERVER'     => '',
			'=PORT'       => '',
			'=ENCRYPTION' => '',
			'=LINK'       => '',
			'=EXTERNAL_CREDENTIALS_BIT' => 0,
		);

		if ($imapOnly)
		{
			$filter['=SERVICE_TYPE'] = 'imap';
		}

		$service = static::getList(array(
			'select' => array('ID', 'NAME'),
			'filter' => $filter,
			// A row of an older portal carries no flags at all, and a null of them means the same as a
			// zero: the service is an ordinary one.
			'runtime' => array(
				new Entity\ExpressionField(
					'EXTERNAL_CREDENTIALS_BIT',
					'(COALESCE(%s, 0) & ' . self::FLAG_EXTERNAL_CREDENTIALS . ')',
					array('FLAGS'),
				),
			),
			'limit' => 1,
		))->fetch();

		return $service ?: null;
	}

	/**
	 * Tells by a service row whether the connection credentials of that service come from outside:
	 * they are issued elsewhere, delivered with the mailbox and must not be edited on the portal.
	 * The answer belongs to the service, so it is the same for every mailbox of that service.
	 */
	public static function hasExternalCredentials(array $service): bool
	{
		return ((int) ($service['FLAGS'] ?? 0) & self::FLAG_EXTERNAL_CREDENTIALS) > 0;
	}

	/**
	 * The number of the IMAP service of a site by its name, 0 when the site has no such row.
	 *
	 * This is how a name becomes a number: a mailbox handed over to us from outside names the service
	 * it belongs to, and the directory of the services is ours, so nobody else can answer that.
	 *
	 * The directory holds no unique key over a site and a name, so the pick is spelled out: duplicated
	 * rows must not scatter the mailboxes of one site over different services.
	 */
	public static function findImapServiceId(string $siteId, string $name): int
	{
		$name = trim($name);
		if ($siteId === '' || $name === '')
		{
			return 0;
		}

		$service = static::getList([
			'select' => ['ID'],
			'filter' => [
				'=SITE_ID' => $siteId,
				'=NAME' => $name,
				'=SERVICE_TYPE' => 'imap',
			],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return $service ? (int)$service['ID'] : 0;
	}

	public static function getOAuthHelper($data)
	{
		switch ($data['NAME'])
		{
			case 'gmail':
				return Helper\OAuth\Google::getInstance();
			case 'yandex':
				return Helper\OAuth\Yandex::getInstance();
			case 'mail.ru':
				return Helper\OAuth\Mailru::getInstance();
			case 'office365':
			case 'outlook.com':
			case 'exchangeOnline':
				return Helper\OAuth\Office365::getInstance();
		}
	}

	public static function delete($primary): Entity\DeleteResult
	{
		$serviceForDelete = static::getByPrimary($primary)->fetch();
		if (!$serviceForDelete)
		{
			$deleteResult = new Entity\DeleteResult();
			$deleteResult->addError(new Entity\EntityError(Localization\Loc::getMessage('mail_mailservice_not_found')));

			return $deleteResult;
		}

		$deleteResult = parent::delete($primary);

		if ($deleteResult->isSuccess())
		{
			$serviceId = is_array($primary) ? $primary['ID'] : $primary;

			if (in_array($serviceForDelete['SERVICE_TYPE'], array('controller', 'domain', 'crdomain')))
			{
				$mbData = array('ACTIVE' => 'N', 'SERVICE_ID' => 0);
			}
			else
			{
				$emptyService = static::findEmptyService($serviceForDelete['SITE_ID'], false);

				$mbData = $emptyService
					? array('SERVICE_ID' => $emptyService['ID'], 'NAME' => $emptyService['NAME'])
					: array('ACTIVE' => 'N', 'SERVICE_ID' => 0);
			}

			$selectResult = \CMailbox::getList(array(), array('SERVICE_ID' => $serviceId));
			while ($mailbox = $selectResult->fetch())
				\CMailbox::update($mailbox['ID'], $mbData);
		}

		return $deleteResult;
	}

	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			),
			'SITE_ID' => array(
				'data_type' => 'string',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_site_field'),
				'required'  => true
			),
			'ACTIVE' => array(
				'data_type' => 'boolean',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_active_field'),
				'values'    => array('N', 'Y'),
				'required'  => true
			),
			'SORT' => array(
				'data_type' => 'integer',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_sort_field')
			),
			'SERVICE_TYPE' => array(
				'data_type' => 'enum',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_type_field'),
				'values'    => array('imap', 'controller', 'domain', 'crdomain'),
				'required'  => true
			),
			'NAME' => array(
				'data_type' => 'string',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_name_field'),
				'required'  => true
			),
			'SERVER' => array(
				'data_type' => 'string',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_server_field'),
				'save_data_modification' => function()
				{
					return array(
						function ($value)
						{
							return mb_strtolower($value);
						}
					);
				},
				'fetch_data_modification' => function()
				{
					return array(
						function ($value)
						{
							return mb_strtolower($value);
						}
					);
				},
			),
			'PORT' => array(
				'data_type' => 'integer',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_port_field'),
			),
			'ENCRYPTION' => array(
				'data_type' => 'boolean',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_encryption_field'),
				'values'    => array('N', 'Y'),
			),
			'LINK' => array(
				'data_type' => 'string',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_link_field'),
			),
			'ICON' => array(
				'data_type' => 'integer',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_icon_field'),
			),
			'TOKEN' => array(
				'data_type' => 'string',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_token_field'),
			),
			'FLAGS' => array(
				'data_type' => 'integer',
				'title'     => Localization\Loc::getMessage('mail_mailservice_entity_flags_field'),
			),
			'SITE' => array(
				'data_type' => 'Bitrix\Main\Site',
				'reference' => array('=this.SITE_ID' => 'ref.LID'),
			),
			new Entity\StringField('SMTP_SERVER'),
			new Entity\IntegerField('SMTP_PORT'),
			new Entity\BooleanField('SMTP_LOGIN_AS_IMAP', [
				'values' => array('N', 'Y'),
				'default_value' => 'N',
			]),
			new Entity\BooleanField('SMTP_PASSWORD_AS_IMAP', [
				'values' => array('N', 'Y'),
				'default_value' => 'N',
			]),
			new ORM\Fields\BooleanField(
				'SMTP_ENCRYPTION',
				array(
					'values' => array('N', 'Y'),
				)
			),
			new ORM\Fields\BooleanField(
				'UPLOAD_OUTGOING',
				array(
					'values' => array('N', 'Y'),
				)
			),
		);
	}

}
