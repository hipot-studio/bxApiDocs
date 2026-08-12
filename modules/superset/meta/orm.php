<?php

/* ORMENTITYANNOTATION:Bitrix\Superset\Internal\Models\ServerTable */
namespace Bitrix\Superset\Internal\Models {
	/**
	 * EO_Server
	 * @see \Bitrix\Superset\Internal\Models\ServerTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getHost()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setHost(\string|\Bitrix\Main\DB\SqlExpression $host)
	 * @method bool hasHost()
	 * @method bool isHostFilled()
	 * @method bool isHostChanged()
	 * @method \string remindActualHost()
	 * @method \string requireHost()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetHost()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetHost()
	 * @method \string fillHost()
	 * @method \string getAccessPassword()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setAccessPassword(\string|\Bitrix\Main\DB\SqlExpression $accessPassword)
	 * @method bool hasAccessPassword()
	 * @method bool isAccessPasswordFilled()
	 * @method bool isAccessPasswordChanged()
	 * @method \string remindActualAccessPassword()
	 * @method \string requireAccessPassword()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetAccessPassword()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetAccessPassword()
	 * @method \string fillAccessPassword()
	 * @method null|\string getInstanceKey()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setInstanceKey(null|\string|\Bitrix\Main\DB\SqlExpression $instanceKey)
	 * @method bool hasInstanceKey()
	 * @method bool isInstanceKeyFilled()
	 * @method bool isInstanceKeyChanged()
	 * @method null|\string remindActualInstanceKey()
	 * @method null|\string requireInstanceKey()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetInstanceKey()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetInstanceKey()
	 * @method null|\string fillInstanceKey()
	 * @method null|\string getInstanceUsername()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setInstanceUsername(null|\string|\Bitrix\Main\DB\SqlExpression $instanceUsername)
	 * @method bool hasInstanceUsername()
	 * @method bool isInstanceUsernameFilled()
	 * @method bool isInstanceUsernameChanged()
	 * @method null|\string remindActualInstanceUsername()
	 * @method null|\string requireInstanceUsername()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetInstanceUsername()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetInstanceUsername()
	 * @method null|\string fillInstanceUsername()
	 * @method \string getToken()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setToken(\string|\Bitrix\Main\DB\SqlExpression $token)
	 * @method bool hasToken()
	 * @method bool isTokenFilled()
	 * @method bool isTokenChanged()
	 * @method \string remindActualToken()
	 * @method \string requireToken()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetToken()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetToken()
	 * @method \string fillToken()
	 * @method \string getRefreshToken()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setRefreshToken(\string|\Bitrix\Main\DB\SqlExpression $refreshToken)
	 * @method bool hasRefreshToken()
	 * @method bool isRefreshTokenFilled()
	 * @method bool isRefreshTokenChanged()
	 * @method \string remindActualRefreshToken()
	 * @method \string requireRefreshToken()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetRefreshToken()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetRefreshToken()
	 * @method \string fillRefreshToken()
	 * @method \string getStartJobId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setStartJobId(\string|\Bitrix\Main\DB\SqlExpression $startJobId)
	 * @method bool hasStartJobId()
	 * @method bool isStartJobIdFilled()
	 * @method bool isStartJobIdChanged()
	 * @method \string remindActualStartJobId()
	 * @method \string requireStartJobId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetStartJobId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetStartJobId()
	 * @method \string fillStartJobId()
	 * @method \int getAccountId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setAccountId(\int|\Bitrix\Main\DB\SqlExpression $accountId)
	 * @method bool hasAccountId()
	 * @method bool isAccountIdFilled()
	 * @method bool isAccountIdChanged()
	 * @method \int remindActualAccountId()
	 * @method \int requireAccountId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetAccountId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetAccountId()
	 * @method \int fillAccountId()
	 * @method \int getVersion()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setVersion(\int|\Bitrix\Main\DB\SqlExpression $version)
	 * @method bool hasVersion()
	 * @method bool isVersionFilled()
	 * @method bool isVersionChanged()
	 * @method \int remindActualVersion()
	 * @method \int requireVersion()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetVersion()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetVersion()
	 * @method \int fillVersion()
	 * @method \string getIsPortalIdVerified()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setIsPortalIdVerified(\string|\Bitrix\Main\DB\SqlExpression $isPortalIdVerified)
	 * @method bool hasIsPortalIdVerified()
	 * @method bool isIsPortalIdVerifiedFilled()
	 * @method bool isIsPortalIdVerifiedChanged()
	 * @method \string remindActualIsPortalIdVerified()
	 * @method \string requireIsPortalIdVerified()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetIsPortalIdVerified()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetIsPortalIdVerified()
	 * @method \string fillIsPortalIdVerified()
	 * @method null|\string getPortalId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setPortalId(null|\string|\Bitrix\Main\DB\SqlExpression $portalId)
	 * @method bool hasPortalId()
	 * @method bool isPortalIdFilled()
	 * @method bool isPortalIdChanged()
	 * @method null|\string remindActualPortalId()
	 * @method null|\string requirePortalId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetPortalId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetPortalId()
	 * @method null|\string fillPortalId()
	 * @method null|\string getPortalUrl()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setPortalUrl(null|\string|\Bitrix\Main\DB\SqlExpression $portalUrl)
	 * @method bool hasPortalUrl()
	 * @method bool isPortalUrlFilled()
	 * @method bool isPortalUrlChanged()
	 * @method null|\string remindActualPortalUrl()
	 * @method null|\string requirePortalUrl()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetPortalUrl()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetPortalUrl()
	 * @method null|\string fillPortalUrl()
	 * @method \string getJwtSecret()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setJwtSecret(\string|\Bitrix\Main\DB\SqlExpression $jwtSecret)
	 * @method bool hasJwtSecret()
	 * @method bool isJwtSecretFilled()
	 * @method bool isJwtSecretChanged()
	 * @method \string remindActualJwtSecret()
	 * @method \string requireJwtSecret()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetJwtSecret()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetJwtSecret()
	 * @method \string fillJwtSecret()
	 * @method null|\Bitrix\Main\Type\DateTime getDateStartAttempt()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setDateStartAttempt(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateStartAttempt)
	 * @method bool hasDateStartAttempt()
	 * @method bool isDateStartAttemptFilled()
	 * @method bool isDateStartAttemptChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualDateStartAttempt()
	 * @method null|\Bitrix\Main\Type\DateTime requireDateStartAttempt()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetDateStartAttempt()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetDateStartAttempt()
	 * @method null|\Bitrix\Main\Type\DateTime fillDateStartAttempt()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetDateCreate()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method \Bitrix\Main\Type\DateTime getDateUpdate()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server setDateUpdate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateUpdate)
	 * @method bool hasDateUpdate()
	 * @method bool isDateUpdateFilled()
	 * @method bool isDateUpdateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateUpdate()
	 * @method \Bitrix\Main\Type\DateTime requireDateUpdate()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server resetDateUpdate()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unsetDateUpdate()
	 * @method \Bitrix\Main\Type\DateTime fillDateUpdate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server set($fieldName, $value)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server reset($fieldName)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Superset\Internal\Models\EO_Server wakeUp($data)
	 */
	class EO_Server extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Superset\Internal\Models\ServerTable */
		static public $dataClass = '\Bitrix\Superset\Internal\Models\ServerTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Superset\Internal\Models {
	/**
	 * EO_Server_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getHostList()
	 * @method \string[] fillHost()
	 * @method \string[] getAccessPasswordList()
	 * @method \string[] fillAccessPassword()
	 * @method null|\string[] getInstanceKeyList()
	 * @method null|\string[] fillInstanceKey()
	 * @method null|\string[] getInstanceUsernameList()
	 * @method null|\string[] fillInstanceUsername()
	 * @method \string[] getTokenList()
	 * @method \string[] fillToken()
	 * @method \string[] getRefreshTokenList()
	 * @method \string[] fillRefreshToken()
	 * @method \string[] getStartJobIdList()
	 * @method \string[] fillStartJobId()
	 * @method \int[] getAccountIdList()
	 * @method \int[] fillAccountId()
	 * @method \int[] getVersionList()
	 * @method \int[] fillVersion()
	 * @method \string[] getIsPortalIdVerifiedList()
	 * @method \string[] fillIsPortalIdVerified()
	 * @method null|\string[] getPortalIdList()
	 * @method null|\string[] fillPortalId()
	 * @method null|\string[] getPortalUrlList()
	 * @method null|\string[] fillPortalUrl()
	 * @method \string[] getJwtSecretList()
	 * @method \string[] fillJwtSecret()
	 * @method null|\Bitrix\Main\Type\DateTime[] getDateStartAttemptList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillDateStartAttempt()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method \Bitrix\Main\Type\DateTime[] getDateUpdateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateUpdate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Superset\Internal\Models\EO_Server $object)
	 * @method bool has(\Bitrix\Superset\Internal\Models\EO_Server $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server getByPrimary($primary)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server[] getAll()
	 * @method bool remove(\Bitrix\Superset\Internal\Models\EO_Server $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Superset\Internal\Models\EO_Server_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Superset\Internal\Models\EO_Server current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Superset\Internal\Models\EO_Server_Collection merge(?\Bitrix\Superset\Internal\Models\EO_Server_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server|null find(callable $callback)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server_Collection filter(callable $callback)
	 */
	class EO_Server_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Superset\Internal\Models\ServerTable */
		static public $dataClass = '\Bitrix\Superset\Internal\Models\ServerTable';
	}
}
namespace Bitrix\Superset\Internal\Models {
	/**
	 * @method static EO_Server_Query query()
	 * @method static EO_Server_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Server_Result getById($id)
	 * @method static EO_Server_Result getList(array $parameters = [])
	 * @method static EO_Server_Entity getEntity()
	 * @method static \Bitrix\Superset\Internal\Models\EO_Server createObject($setDefaultValues = true)
	 * @method static \Bitrix\Superset\Internal\Models\EO_Server_Collection createCollection()
	 * @method static \Bitrix\Superset\Internal\Models\EO_Server wakeUpObject($row)
	 * @method static \Bitrix\Superset\Internal\Models\EO_Server_Collection wakeUpCollection($rows)
	 */
	class ServerTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Server_Result exec()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server fetchObject()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server_Collection fetchCollection()
	 */
	class EO_Server_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Superset\Internal\Models\EO_Server fetchObject()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server_Collection fetchCollection()
	 */
	class EO_Server_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Superset\Internal\Models\EO_Server createObject($setDefaultValues = true)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server_Collection createCollection()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server wakeUpObject($row)
	 * @method \Bitrix\Superset\Internal\Models\EO_Server_Collection wakeUpCollection($rows)
	 */
	class EO_Server_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Superset\Internal\Models\UserTable */
namespace Bitrix\Superset\Internal\Models {
	/**
	 * EO_User
	 * @see \Bitrix\Superset\Internal\Models\UserTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getLogin()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setLogin(\string|\Bitrix\Main\DB\SqlExpression $login)
	 * @method bool hasLogin()
	 * @method bool isLoginFilled()
	 * @method bool isLoginChanged()
	 * @method \string remindActualLogin()
	 * @method \string requireLogin()
	 * @method \Bitrix\Superset\Internal\Models\EO_User resetLogin()
	 * @method \Bitrix\Superset\Internal\Models\EO_User unsetLogin()
	 * @method \string fillLogin()
	 * @method \string getAccessPassword()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setAccessPassword(\string|\Bitrix\Main\DB\SqlExpression $accessPassword)
	 * @method bool hasAccessPassword()
	 * @method bool isAccessPasswordFilled()
	 * @method bool isAccessPasswordChanged()
	 * @method \string remindActualAccessPassword()
	 * @method \string requireAccessPassword()
	 * @method \Bitrix\Superset\Internal\Models\EO_User resetAccessPassword()
	 * @method \Bitrix\Superset\Internal\Models\EO_User unsetAccessPassword()
	 * @method \string fillAccessPassword()
	 * @method \int getServerId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setServerId(\int|\Bitrix\Main\DB\SqlExpression $serverId)
	 * @method bool hasServerId()
	 * @method bool isServerIdFilled()
	 * @method bool isServerIdChanged()
	 * @method \int remindActualServerId()
	 * @method \int requireServerId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User resetServerId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User unsetServerId()
	 * @method \int fillServerId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server getServer()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server remindActualServer()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server requireServer()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setServer(\Bitrix\Superset\Internal\Models\EO_Server $object)
	 * @method \Bitrix\Superset\Internal\Models\EO_User resetServer()
	 * @method \Bitrix\Superset\Internal\Models\EO_User unsetServer()
	 * @method bool hasServer()
	 * @method bool isServerFilled()
	 * @method bool isServerChanged()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server fillServer()
	 * @method \Bitrix\Main\Type\DateTime getCreated()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setCreated(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $created)
	 * @method bool hasCreated()
	 * @method bool isCreatedFilled()
	 * @method bool isCreatedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreated()
	 * @method \Bitrix\Main\Type\DateTime requireCreated()
	 * @method \Bitrix\Superset\Internal\Models\EO_User resetCreated()
	 * @method \Bitrix\Superset\Internal\Models\EO_User unsetCreated()
	 * @method \Bitrix\Main\Type\DateTime fillCreated()
	 * @method \Bitrix\Main\Type\DateTime getUpdated()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setUpdated(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updated)
	 * @method bool hasUpdated()
	 * @method bool isUpdatedFilled()
	 * @method bool isUpdatedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdated()
	 * @method \Bitrix\Main\Type\DateTime requireUpdated()
	 * @method \Bitrix\Superset\Internal\Models\EO_User resetUpdated()
	 * @method \Bitrix\Superset\Internal\Models\EO_User unsetUpdated()
	 * @method \Bitrix\Main\Type\DateTime fillUpdated()
	 * @method \int getExternalId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setExternalId(\int|\Bitrix\Main\DB\SqlExpression $externalId)
	 * @method bool hasExternalId()
	 * @method bool isExternalIdFilled()
	 * @method bool isExternalIdChanged()
	 * @method \int remindActualExternalId()
	 * @method \int requireExternalId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User resetExternalId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User unsetExternalId()
	 * @method \int fillExternalId()
	 * @method \string getClientId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User setClientId(\string|\Bitrix\Main\DB\SqlExpression $clientId)
	 * @method bool hasClientId()
	 * @method bool isClientIdFilled()
	 * @method bool isClientIdChanged()
	 * @method \string remindActualClientId()
	 * @method \string requireClientId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User resetClientId()
	 * @method \Bitrix\Superset\Internal\Models\EO_User unsetClientId()
	 * @method \string fillClientId()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Superset\Internal\Models\EO_User set($fieldName, $value)
	 * @method \Bitrix\Superset\Internal\Models\EO_User reset($fieldName)
	 * @method \Bitrix\Superset\Internal\Models\EO_User unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Superset\Internal\Models\EO_User wakeUp($data)
	 */
	class EO_User extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Superset\Internal\Models\UserTable */
		static public $dataClass = '\Bitrix\Superset\Internal\Models\UserTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Superset\Internal\Models {
	/**
	 * EO_User_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getLoginList()
	 * @method \string[] fillLogin()
	 * @method \string[] getAccessPasswordList()
	 * @method \string[] fillAccessPassword()
	 * @method \int[] getServerIdList()
	 * @method \int[] fillServerId()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server[] getServerList()
	 * @method \Bitrix\Superset\Internal\Models\EO_User_Collection getServerCollection()
	 * @method \Bitrix\Superset\Internal\Models\EO_Server_Collection fillServer()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreated()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdated()
	 * @method \int[] getExternalIdList()
	 * @method \int[] fillExternalId()
	 * @method \string[] getClientIdList()
	 * @method \string[] fillClientId()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Superset\Internal\Models\EO_User $object)
	 * @method bool has(\Bitrix\Superset\Internal\Models\EO_User $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Superset\Internal\Models\EO_User getByPrimary($primary)
	 * @method \Bitrix\Superset\Internal\Models\EO_User[] getAll()
	 * @method bool remove(\Bitrix\Superset\Internal\Models\EO_User $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Superset\Internal\Models\EO_User_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Superset\Internal\Models\EO_User current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Superset\Internal\Models\EO_User_Collection merge(?\Bitrix\Superset\Internal\Models\EO_User_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Superset\Internal\Models\EO_User|null find(callable $callback)
	 * @method \Bitrix\Superset\Internal\Models\EO_User_Collection filter(callable $callback)
	 */
	class EO_User_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Superset\Internal\Models\UserTable */
		static public $dataClass = '\Bitrix\Superset\Internal\Models\UserTable';
	}
}
namespace Bitrix\Superset\Internal\Models {
	/**
	 * @method static EO_User_Query query()
	 * @method static EO_User_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_User_Result getById($id)
	 * @method static EO_User_Result getList(array $parameters = [])
	 * @method static EO_User_Entity getEntity()
	 * @method static \Bitrix\Superset\Internal\Models\EO_User createObject($setDefaultValues = true)
	 * @method static \Bitrix\Superset\Internal\Models\EO_User_Collection createCollection()
	 * @method static \Bitrix\Superset\Internal\Models\EO_User wakeUpObject($row)
	 * @method static \Bitrix\Superset\Internal\Models\EO_User_Collection wakeUpCollection($rows)
	 */
	class UserTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_User_Result exec()
	 * @method \Bitrix\Superset\Internal\Models\EO_User fetchObject()
	 * @method \Bitrix\Superset\Internal\Models\EO_User_Collection fetchCollection()
	 */
	class EO_User_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Superset\Internal\Models\EO_User fetchObject()
	 * @method \Bitrix\Superset\Internal\Models\EO_User_Collection fetchCollection()
	 */
	class EO_User_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Superset\Internal\Models\EO_User createObject($setDefaultValues = true)
	 * @method \Bitrix\Superset\Internal\Models\EO_User_Collection createCollection()
	 * @method \Bitrix\Superset\Internal\Models\EO_User wakeUpObject($row)
	 * @method \Bitrix\Superset\Internal\Models\EO_User_Collection wakeUpCollection($rows)
	 */
	class EO_User_Entity extends \Bitrix\Main\ORM\Entity {}
}