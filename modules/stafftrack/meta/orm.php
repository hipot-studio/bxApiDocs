<?php

/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Internal\Model\AddressQueueTable:stafftrack/lib/internal/model/addressqueuetable.php */
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * EO_AddressQueue
	 * @see \Bitrix\StaffTrack\Internal\Model\AddressQueueTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getCheckInId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue setCheckInId(\int|\Bitrix\Main\DB\SqlExpression $checkInId)
	 * @method bool hasCheckInId()
	 * @method bool isCheckInIdFilled()
	 * @method bool isCheckInIdChanged()
	 * @method \int getViewerId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue setViewerId(\int|\Bitrix\Main\DB\SqlExpression $viewerId)
	 * @method bool hasViewerId()
	 * @method bool isViewerIdFilled()
	 * @method bool isViewerIdChanged()
	 * @method \string getGeohash()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue setGeohash(\string|\Bitrix\Main\DB\SqlExpression $geohash)
	 * @method bool hasGeohash()
	 * @method bool isGeohashFilled()
	 * @method bool isGeohashChanged()
	 * @method \string remindActualGeohash()
	 * @method \string requireGeohash()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue resetGeohash()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue unsetGeohash()
	 * @method \string fillGeohash()
	 * @method \int getUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue resetUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue resetDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
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
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue reset($fieldName)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue wakeUp($data)
	 */
	class EO_AddressQueue extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Internal\Model\AddressQueueTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\AddressQueueTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * EO_AddressQueue_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getCheckInIdList()
	 * @method \int[] getViewerIdList()
	 * @method \string[] getGeohashList()
	 * @method \string[] fillGeohash()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Internal\Model\EO_AddressQueue $object)
	 * @method bool has(\Bitrix\StaffTrack\Internal\Model\EO_AddressQueue $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Internal\Model\EO_AddressQueue $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection merge(?\Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection filter(callable $callback)
	 */
	class EO_AddressQueue_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Internal\Model\AddressQueueTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\AddressQueueTable';
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_AddressQueue_Result exec()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection fetchCollection()
	 */
	class EO_AddressQueue_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection fetchCollection()
	 */
	class EO_AddressQueue_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection wakeUpCollection($rows)
	 */
	class EO_AddressQueue_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Internal\Model\CheckInTable:stafftrack/lib/internal/model/checkintable.php */
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * CheckIn
	 * @see \Bitrix\StaffTrack\Internal\Model\CheckInTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method \int getEntityType()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setEntityType(\int|\Bitrix\Main\DB\SqlExpression $entityType)
	 * @method bool hasEntityType()
	 * @method bool isEntityTypeFilled()
	 * @method bool isEntityTypeChanged()
	 * @method \int remindActualEntityType()
	 * @method \int requireEntityType()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetEntityType()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetEntityType()
	 * @method \int fillEntityType()
	 * @method null|\float getLatitude()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setLatitude(null|\float|\Bitrix\Main\DB\SqlExpression $latitude)
	 * @method bool hasLatitude()
	 * @method bool isLatitudeFilled()
	 * @method bool isLatitudeChanged()
	 * @method null|\float remindActualLatitude()
	 * @method null|\float requireLatitude()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetLatitude()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetLatitude()
	 * @method null|\float fillLatitude()
	 * @method null|\float getLongitude()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setLongitude(null|\float|\Bitrix\Main\DB\SqlExpression $longitude)
	 * @method bool hasLongitude()
	 * @method bool isLongitudeFilled()
	 * @method bool isLongitudeChanged()
	 * @method null|\float remindActualLongitude()
	 * @method null|\float requireLongitude()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetLongitude()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetLongitude()
	 * @method null|\float fillLongitude()
	 * @method null|\string getDescription()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setDescription(null|\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method null|\string remindActualDescription()
	 * @method null|\string requireDescription()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetDescription()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetDescription()
	 * @method null|\string fillDescription()
	 * @method null|\string getAddress()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setAddress(null|\string|\Bitrix\Main\DB\SqlExpression $address)
	 * @method bool hasAddress()
	 * @method bool isAddressFilled()
	 * @method bool isAddressChanged()
	 * @method null|\string remindActualAddress()
	 * @method null|\string requireAddress()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetAddress()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetAddress()
	 * @method null|\string fillAddress()
	 * @method null|\string getMessageIds()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setMessageIds(null|\string|\Bitrix\Main\DB\SqlExpression $messageIds)
	 * @method bool hasMessageIds()
	 * @method bool isMessageIdsFilled()
	 * @method bool isMessageIdsChanged()
	 * @method null|\string remindActualMessageIds()
	 * @method null|\string requireMessageIds()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetMessageIds()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetMessageIds()
	 * @method null|\string fillMessageIds()
	 * @method null|\int getUserTimezone()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setUserTimezone(null|\int|\Bitrix\Main\DB\SqlExpression $userTimezone)
	 * @method bool hasUserTimezone()
	 * @method bool isUserTimezoneFilled()
	 * @method bool isUserTimezoneChanged()
	 * @method null|\int remindActualUserTimezone()
	 * @method null|\int requireUserTimezone()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetUserTimezone()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetUserTimezone()
	 * @method null|\int fillUserTimezone()
	 * @method null|\string getFileIds()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn setFileIds(null|\string|\Bitrix\Main\DB\SqlExpression $fileIds)
	 * @method bool hasFileIds()
	 * @method bool isFileIdsFilled()
	 * @method bool isFileIdsChanged()
	 * @method null|\string remindActualFileIds()
	 * @method null|\string requireFileIds()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn resetFileIds()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unsetFileIds()
	 * @method null|\string fillFileIds()
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
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn reset($fieldName)
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Internal\Model\CheckIn wakeUp($data)
	 */
	class EO_CheckIn extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Internal\Model\CheckInTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\CheckInTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * CheckInCollection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method \int[] getEntityTypeList()
	 * @method \int[] fillEntityType()
	 * @method null|\float[] getLatitudeList()
	 * @method null|\float[] fillLatitude()
	 * @method null|\float[] getLongitudeList()
	 * @method null|\float[] fillLongitude()
	 * @method null|\string[] getDescriptionList()
	 * @method null|\string[] fillDescription()
	 * @method null|\string[] getAddressList()
	 * @method null|\string[] fillAddress()
	 * @method null|\string[] getMessageIdsList()
	 * @method null|\string[] fillMessageIds()
	 * @method null|\int[] getUserTimezoneList()
	 * @method null|\int[] fillUserTimezone()
	 * @method null|\string[] getFileIdsList()
	 * @method null|\string[] fillFileIds()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Internal\Model\CheckIn $object)
	 * @method bool has(\Bitrix\StaffTrack\Internal\Model\CheckIn $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Internal\Model\CheckIn $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Internal\Model\CheckInCollection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckInCollection merge(?\Bitrix\StaffTrack\Internal\Model\CheckInCollection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckInCollection filter(callable $callback)
	 */
	class EO_CheckIn_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Internal\Model\CheckInTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\CheckInTable';
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CheckIn_Result exec()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckInCollection fetchCollection()
	 */
	class EO_CheckIn_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckInCollection fetchCollection()
	 */
	class EO_CheckIn_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckInCollection createCollection()
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckIn wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Internal\Model\CheckInCollection wakeUpCollection($rows)
	 */
	class EO_CheckIn_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable:stafftrack/lib/internal/model/checkindailystatstable.php */
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * EO_CheckInDailyStats
	 * @see \Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats resetUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\Date getLocalDate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats setLocalDate(\Bitrix\Main\Type\Date|\Bitrix\Main\DB\SqlExpression $localDate)
	 * @method bool hasLocalDate()
	 * @method bool isLocalDateFilled()
	 * @method bool isLocalDateChanged()
	 * @method \Bitrix\Main\Type\Date remindActualLocalDate()
	 * @method \Bitrix\Main\Type\Date requireLocalDate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats resetLocalDate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats unsetLocalDate()
	 * @method \Bitrix\Main\Type\Date fillLocalDate()
	 * @method \int getCnt()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats setCnt(\int|\Bitrix\Main\DB\SqlExpression $cnt)
	 * @method bool hasCnt()
	 * @method bool isCntFilled()
	 * @method bool isCntChanged()
	 * @method \int remindActualCnt()
	 * @method \int requireCnt()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats resetCnt()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats unsetCnt()
	 * @method \int fillCnt()
	 * @method \int getHasOpenEnter()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats setHasOpenEnter(\int|\Bitrix\Main\DB\SqlExpression $hasOpenEnter)
	 * @method bool hasHasOpenEnter()
	 * @method bool isHasOpenEnterFilled()
	 * @method bool isHasOpenEnterChanged()
	 * @method \int remindActualHasOpenEnter()
	 * @method \int requireHasOpenEnter()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats resetHasOpenEnter()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats unsetHasOpenEnter()
	 * @method \int fillHasOpenEnter()
	 * @method \int getLastCheckInId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats setLastCheckInId(\int|\Bitrix\Main\DB\SqlExpression $lastCheckInId)
	 * @method bool hasLastCheckInId()
	 * @method bool isLastCheckInIdFilled()
	 * @method bool isLastCheckInIdChanged()
	 * @method \int remindActualLastCheckInId()
	 * @method \int requireLastCheckInId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats resetLastCheckInId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats unsetLastCheckInId()
	 * @method \int fillLastCheckInId()
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
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats reset($fieldName)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats wakeUp($data)
	 */
	class EO_CheckInDailyStats extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * EO_CheckInDailyStats_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\Date[] getLocalDateList()
	 * @method \Bitrix\Main\Type\Date[] fillLocalDate()
	 * @method \int[] getCntList()
	 * @method \int[] fillCnt()
	 * @method \int[] getHasOpenEnterList()
	 * @method \int[] fillHasOpenEnter()
	 * @method \int[] getLastCheckInIdList()
	 * @method \int[] fillLastCheckInId()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats $object)
	 * @method bool has(\Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection merge(?\Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection filter(callable $callback)
	 */
	class EO_CheckInDailyStats_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable';
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CheckInDailyStats_Result exec()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection fetchCollection()
	 */
	class EO_CheckInDailyStats_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection fetchCollection()
	 */
	class EO_CheckInDailyStats_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection wakeUpCollection($rows)
	 */
	class EO_CheckInDailyStats_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Internal\Model\CheckInByDayTable:stafftrack/lib/internal/model/checkinbydaytable.php */
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * EO_CheckInByDay
	 * @see \Bitrix\StaffTrack\Internal\Model\CheckInByDayTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay resetUserId()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay resetDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method \Bitrix\Main\Type\DateTime getDateEnd()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay setDateEnd(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateEnd)
	 * @method bool hasDateEnd()
	 * @method bool isDateEndFilled()
	 * @method bool isDateEndChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateEnd()
	 * @method \Bitrix\Main\Type\DateTime requireDateEnd()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay resetDateEnd()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay unsetDateEnd()
	 * @method \Bitrix\Main\Type\DateTime fillDateEnd()
	 * @method \string getGeoData()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay setGeoData(\string|\Bitrix\Main\DB\SqlExpression $geoData)
	 * @method bool hasGeoData()
	 * @method bool isGeoDataFilled()
	 * @method bool isGeoDataChanged()
	 * @method \string remindActualGeoData()
	 * @method \string requireGeoData()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay resetGeoData()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay unsetGeoData()
	 * @method \string fillGeoData()
	 * @method \int getCnt()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay setCnt(\int|\Bitrix\Main\DB\SqlExpression $cnt)
	 * @method bool hasCnt()
	 * @method bool isCntFilled()
	 * @method bool isCntChanged()
	 * @method \int remindActualCnt()
	 * @method \int requireCnt()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay resetCnt()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay unsetCnt()
	 * @method \int fillCnt()
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
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay reset($fieldName)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay wakeUp($data)
	 */
	class EO_CheckInByDay extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Internal\Model\CheckInByDayTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\CheckInByDayTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * EO_CheckInByDay_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method \Bitrix\Main\Type\DateTime[] getDateEndList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateEnd()
	 * @method \string[] getGeoDataList()
	 * @method \string[] fillGeoData()
	 * @method \int[] getCntList()
	 * @method \int[] fillCnt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay $object)
	 * @method bool has(\Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection merge(?\Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection filter(callable $callback)
	 */
	class EO_CheckInByDay_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Internal\Model\CheckInByDayTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\CheckInByDayTable';
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CheckInByDay_Result exec()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection fetchCollection()
	 */
	class EO_CheckInByDay_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection fetchCollection()
	 */
	class EO_CheckInByDay_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection wakeUpCollection($rows)
	 */
	class EO_CheckInByDay_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Internal\Model\AddressTable:stafftrack/lib/internal/model/addresstable.php */
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * EO_Address
	 * @see \Bitrix\StaffTrack\Internal\Model\AddressTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getGeohash()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address setGeohash(\string|\Bitrix\Main\DB\SqlExpression $geohash)
	 * @method bool hasGeohash()
	 * @method bool isGeohashFilled()
	 * @method bool isGeohashChanged()
	 * @method null|\string getAddress()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address setAddress(null|\string|\Bitrix\Main\DB\SqlExpression $address)
	 * @method bool hasAddress()
	 * @method bool isAddressFilled()
	 * @method bool isAddressChanged()
	 * @method null|\string remindActualAddress()
	 * @method null|\string requireAddress()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address resetAddress()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address unsetAddress()
	 * @method null|\string fillAddress()
	 * @method \int getStatus()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address resetStatus()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address unsetStatus()
	 * @method \int fillStatus()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address resetDateCreate()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method null|\Bitrix\Main\Type\DateTime getDateResolve()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address setDateResolve(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateResolve)
	 * @method bool hasDateResolve()
	 * @method bool isDateResolveFilled()
	 * @method bool isDateResolveChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualDateResolve()
	 * @method null|\Bitrix\Main\Type\DateTime requireDateResolve()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address resetDateResolve()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address unsetDateResolve()
	 * @method null|\Bitrix\Main\Type\DateTime fillDateResolve()
	 * @method \Bitrix\Main\Type\DateTime getLastUsed()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address setLastUsed(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $lastUsed)
	 * @method bool hasLastUsed()
	 * @method bool isLastUsedFilled()
	 * @method bool isLastUsedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualLastUsed()
	 * @method \Bitrix\Main\Type\DateTime requireLastUsed()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address resetLastUsed()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address unsetLastUsed()
	 * @method \Bitrix\Main\Type\DateTime fillLastUsed()
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
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address reset($fieldName)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Internal\Model\EO_Address wakeUp($data)
	 */
	class EO_Address extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Internal\Model\AddressTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\AddressTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * EO_Address_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getGeohashList()
	 * @method null|\string[] getAddressList()
	 * @method null|\string[] fillAddress()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method null|\Bitrix\Main\Type\DateTime[] getDateResolveList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillDateResolve()
	 * @method \Bitrix\Main\Type\DateTime[] getLastUsedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillLastUsed()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Internal\Model\EO_Address $object)
	 * @method bool has(\Bitrix\StaffTrack\Internal\Model\EO_Address $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Internal\Model\EO_Address $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection merge(?\Bitrix\StaffTrack\Internal\Model\EO_Address_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection filter(callable $callback)
	 */
	class EO_Address_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Internal\Model\AddressTable */
		static public $dataClass = '\Bitrix\StaffTrack\Internal\Model\AddressTable';
	}
}
namespace Bitrix\StaffTrack\Internal\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Address_Result exec()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection fetchCollection()
	 */
	class EO_Address_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address fetchObject()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection fetchCollection()
	 */
	class EO_Address_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection wakeUpCollection($rows)
	 */
	class EO_Address_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Model\UserStatisticsHashTable:stafftrack/lib/model/userstatisticshashtable.php */
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_UserStatisticsHash
	 * @see \Bitrix\StaffTrack\Model\UserStatisticsHashTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash resetUserId()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash unsetUserId()
	 * @method \int fillUserId()
	 * @method \string getHash()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash setHash(\string|\Bitrix\Main\DB\SqlExpression $hash)
	 * @method bool hasHash()
	 * @method bool isHashFilled()
	 * @method bool isHashChanged()
	 * @method \string remindActualHash()
	 * @method \string requireHash()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash resetHash()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash unsetHash()
	 * @method \string fillHash()
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
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash reset($fieldName)
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Model\EO_UserStatisticsHash wakeUp($data)
	 */
	class EO_UserStatisticsHash extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Model\UserStatisticsHashTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\UserStatisticsHashTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_UserStatisticsHash_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \string[] getHashList()
	 * @method \string[] fillHash()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Model\EO_UserStatisticsHash $object)
	 * @method bool has(\Bitrix\StaffTrack\Model\EO_UserStatisticsHash $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Model\EO_UserStatisticsHash $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Model\EO_UserStatisticsHash_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash_Collection merge(?\Bitrix\StaffTrack\Model\EO_UserStatisticsHash_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash_Collection filter(callable $callback)
	 */
	class EO_UserStatisticsHash_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Model\UserStatisticsHashTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\UserStatisticsHashTable';
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_UserStatisticsHash_Result exec()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash_Collection fetchCollection()
	 */
	class EO_UserStatisticsHash_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash_Collection fetchCollection()
	 */
	class EO_UserStatisticsHash_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Model\EO_UserStatisticsHash_Collection wakeUpCollection($rows)
	 */
	class EO_UserStatisticsHash_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Model\ShiftGeoTable:stafftrack/lib/model/shiftgeotable.php */
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_ShiftGeo
	 * @see \Bitrix\StaffTrack\Model\ShiftGeoTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo setShiftId(\int|\Bitrix\Main\DB\SqlExpression $shiftId)
	 * @method bool hasShiftId()
	 * @method bool isShiftIdFilled()
	 * @method bool isShiftIdChanged()
	 * @method \int remindActualShiftId()
	 * @method \int requireShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo resetShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo unsetShiftId()
	 * @method \int fillShiftId()
	 * @method \string getImageUrl()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo setImageUrl(\string|\Bitrix\Main\DB\SqlExpression $imageUrl)
	 * @method bool hasImageUrl()
	 * @method bool isImageUrlFilled()
	 * @method bool isImageUrlChanged()
	 * @method \string remindActualImageUrl()
	 * @method \string requireImageUrl()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo resetImageUrl()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo unsetImageUrl()
	 * @method \string fillImageUrl()
	 * @method \string getAddress()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo setAddress(\string|\Bitrix\Main\DB\SqlExpression $address)
	 * @method bool hasAddress()
	 * @method bool isAddressFilled()
	 * @method bool isAddressChanged()
	 * @method \string remindActualAddress()
	 * @method \string requireAddress()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo resetAddress()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo unsetAddress()
	 * @method \string fillAddress()
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
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo reset($fieldName)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Model\EO_ShiftGeo wakeUp($data)
	 */
	class EO_ShiftGeo extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Model\ShiftGeoTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\ShiftGeoTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_ShiftGeo_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getShiftIdList()
	 * @method \int[] fillShiftId()
	 * @method \string[] getImageUrlList()
	 * @method \string[] fillImageUrl()
	 * @method \string[] getAddressList()
	 * @method \string[] fillAddress()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Model\EO_ShiftGeo $object)
	 * @method bool has(\Bitrix\StaffTrack\Model\EO_ShiftGeo $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Model\EO_ShiftGeo $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection merge(?\Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection filter(callable $callback)
	 */
	class EO_ShiftGeo_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Model\ShiftGeoTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\ShiftGeoTable';
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ShiftGeo_Result exec()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection fetchCollection()
	 */
	class EO_ShiftGeo_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection fetchCollection()
	 */
	class EO_ShiftGeo_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection wakeUpCollection($rows)
	 */
	class EO_ShiftGeo_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Model\ShiftMessageTable:stafftrack/lib/model/shiftmessagetable.php */
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_ShiftMessage
	 * @see \Bitrix\StaffTrack\Model\ShiftMessageTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage setShiftId(\int|\Bitrix\Main\DB\SqlExpression $shiftId)
	 * @method bool hasShiftId()
	 * @method bool isShiftIdFilled()
	 * @method bool isShiftIdChanged()
	 * @method \int remindActualShiftId()
	 * @method \int requireShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage resetShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage unsetShiftId()
	 * @method \int fillShiftId()
	 * @method \int getMessageId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage setMessageId(\int|\Bitrix\Main\DB\SqlExpression $messageId)
	 * @method bool hasMessageId()
	 * @method bool isMessageIdFilled()
	 * @method bool isMessageIdChanged()
	 * @method \int remindActualMessageId()
	 * @method \int requireMessageId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage resetMessageId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage unsetMessageId()
	 * @method \int fillMessageId()
	 * @method \Bitrix\StaffTrack\Model\Shift getShift()
	 * @method \Bitrix\StaffTrack\Model\Shift remindActualShift()
	 * @method \Bitrix\StaffTrack\Model\Shift requireShift()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage setShift(\Bitrix\StaffTrack\Model\Shift $object)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage resetShift()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage unsetShift()
	 * @method bool hasShift()
	 * @method bool isShiftFilled()
	 * @method bool isShiftChanged()
	 * @method \Bitrix\StaffTrack\Model\Shift fillShift()
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
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage reset($fieldName)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Model\EO_ShiftMessage wakeUp($data)
	 */
	class EO_ShiftMessage extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Model\ShiftMessageTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\ShiftMessageTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * ShiftMessageCollection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getShiftIdList()
	 * @method \int[] fillShiftId()
	 * @method \int[] getMessageIdList()
	 * @method \int[] fillMessageId()
	 * @method \Bitrix\StaffTrack\Model\Shift[] getShiftList()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection getShiftCollection()
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection fillShift()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Model\EO_ShiftMessage $object)
	 * @method bool has(\Bitrix\StaffTrack\Model\EO_ShiftMessage $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Model\EO_ShiftMessage $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Model\ShiftMessageCollection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection merge(?\Bitrix\StaffTrack\Model\ShiftMessageCollection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection filter(callable $callback)
	 */
	class EO_ShiftMessage_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Model\ShiftMessageTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\ShiftMessageTable';
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ShiftMessage_Result exec()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage fetchObject()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection fetchCollection()
	 */
	class EO_ShiftMessage_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage fetchObject()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection fetchCollection()
	 */
	class EO_ShiftMessage_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection createCollection()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftMessage wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection wakeUpCollection($rows)
	 */
	class EO_ShiftMessage_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Model\ShiftCancellationTable:stafftrack/lib/model/shiftcancellationtable.php */
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_ShiftCancellation
	 * @see \Bitrix\StaffTrack\Model\ShiftCancellationTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation setShiftId(\int|\Bitrix\Main\DB\SqlExpression $shiftId)
	 * @method bool hasShiftId()
	 * @method bool isShiftIdFilled()
	 * @method bool isShiftIdChanged()
	 * @method \int remindActualShiftId()
	 * @method \int requireShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation resetShiftId()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation unsetShiftId()
	 * @method \int fillShiftId()
	 * @method \string getReason()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation setReason(\string|\Bitrix\Main\DB\SqlExpression $reason)
	 * @method bool hasReason()
	 * @method bool isReasonFilled()
	 * @method bool isReasonChanged()
	 * @method \string remindActualReason()
	 * @method \string requireReason()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation resetReason()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation unsetReason()
	 * @method \string fillReason()
	 * @method \Bitrix\Main\Type\DateTime getDateCancel()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation setDateCancel(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCancel)
	 * @method bool hasDateCancel()
	 * @method bool isDateCancelFilled()
	 * @method bool isDateCancelChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCancel()
	 * @method \Bitrix\Main\Type\DateTime requireDateCancel()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation resetDateCancel()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation unsetDateCancel()
	 * @method \Bitrix\Main\Type\DateTime fillDateCancel()
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
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation reset($fieldName)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Model\EO_ShiftCancellation wakeUp($data)
	 */
	class EO_ShiftCancellation extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Model\ShiftCancellationTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\ShiftCancellationTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_ShiftCancellation_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getShiftIdList()
	 * @method \int[] fillShiftId()
	 * @method \string[] getReasonList()
	 * @method \string[] fillReason()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCancelList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCancel()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Model\EO_ShiftCancellation $object)
	 * @method bool has(\Bitrix\StaffTrack\Model\EO_ShiftCancellation $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Model\EO_ShiftCancellation $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection merge(?\Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection filter(callable $callback)
	 */
	class EO_ShiftCancellation_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Model\ShiftCancellationTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\ShiftCancellationTable';
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ShiftCancellation_Result exec()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection fetchCollection()
	 */
	class EO_ShiftCancellation_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection fetchCollection()
	 */
	class EO_ShiftCancellation_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection wakeUpCollection($rows)
	 */
	class EO_ShiftCancellation_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Model\OptionTable:stafftrack/lib/Model/OptionTable.php */
namespace Bitrix\StaffTrack\Model {
	/**
	 * Option
	 * @see \Bitrix\StaffTrack\Model\OptionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Model\Option setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\StaffTrack\Model\Option setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\StaffTrack\Model\Option resetUserId()
	 * @method \Bitrix\StaffTrack\Model\Option unsetUserId()
	 * @method \int fillUserId()
	 * @method \string getName()
	 * @method \Bitrix\StaffTrack\Model\Option setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\StaffTrack\Model\Option resetName()
	 * @method \Bitrix\StaffTrack\Model\Option unsetName()
	 * @method \string fillName()
	 * @method \string getValue()
	 * @method \Bitrix\StaffTrack\Model\Option setValue(\string|\Bitrix\Main\DB\SqlExpression $value)
	 * @method bool hasValue()
	 * @method bool isValueFilled()
	 * @method bool isValueChanged()
	 * @method \string remindActualValue()
	 * @method \string requireValue()
	 * @method \Bitrix\StaffTrack\Model\Option resetValue()
	 * @method \Bitrix\StaffTrack\Model\Option unsetValue()
	 * @method \string fillValue()
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
	 * @method \Bitrix\StaffTrack\Model\Option set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Model\Option reset($fieldName)
	 * @method \Bitrix\StaffTrack\Model\Option unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Model\Option wakeUp($data)
	 */
	class EO_Option extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Model\OptionTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\OptionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_Option_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getValueList()
	 * @method \string[] fillValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Model\Option $object)
	 * @method bool has(\Bitrix\StaffTrack\Model\Option $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\Option getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\Option[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Model\Option $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Model\EO_Option_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Model\Option current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Model\EO_Option_Collection merge(?\Bitrix\StaffTrack\Model\EO_Option_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Model\Option|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Model\EO_Option_Collection filter(callable $callback)
	 */
	class EO_Option_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Model\OptionTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\OptionTable';
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Option_Result exec()
	 * @method \Bitrix\StaffTrack\Model\Option fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_Option_Collection fetchCollection()
	 */
	class EO_Option_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Model\Option fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_Option_Collection fetchCollection()
	 */
	class EO_Option_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Model\Option createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Model\EO_Option_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Model\Option wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Model\EO_Option_Collection wakeUpCollection($rows)
	 */
	class EO_Option_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Model\ShiftTable:stafftrack/lib/model/shifttable.php */
namespace Bitrix\StaffTrack\Model {
	/**
	 * Shift
	 * @see \Bitrix\StaffTrack\Model\ShiftTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Model\Shift setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\StaffTrack\Model\Shift setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\StaffTrack\Model\Shift resetUserId()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\Date getShiftDate()
	 * @method \Bitrix\StaffTrack\Model\Shift setShiftDate(\Bitrix\Main\Type\Date|\Bitrix\Main\DB\SqlExpression $shiftDate)
	 * @method bool hasShiftDate()
	 * @method bool isShiftDateFilled()
	 * @method bool isShiftDateChanged()
	 * @method \Bitrix\Main\Type\Date remindActualShiftDate()
	 * @method \Bitrix\Main\Type\Date requireShiftDate()
	 * @method \Bitrix\StaffTrack\Model\Shift resetShiftDate()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetShiftDate()
	 * @method \Bitrix\Main\Type\Date fillShiftDate()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\StaffTrack\Model\Shift setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\StaffTrack\Model\Shift resetDateCreate()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method \int getStatus()
	 * @method \Bitrix\StaffTrack\Model\Shift setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\StaffTrack\Model\Shift resetStatus()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetStatus()
	 * @method \int fillStatus()
	 * @method \string getLocation()
	 * @method \Bitrix\StaffTrack\Model\Shift setLocation(\string|\Bitrix\Main\DB\SqlExpression $location)
	 * @method bool hasLocation()
	 * @method bool isLocationFilled()
	 * @method bool isLocationChanged()
	 * @method \string remindActualLocation()
	 * @method \string requireLocation()
	 * @method \Bitrix\StaffTrack\Model\Shift resetLocation()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetLocation()
	 * @method \string fillLocation()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo getGeo()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo remindActualGeo()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo requireGeo()
	 * @method \Bitrix\StaffTrack\Model\Shift setGeo(\Bitrix\StaffTrack\Model\EO_ShiftGeo $object)
	 * @method \Bitrix\StaffTrack\Model\Shift resetGeo()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetGeo()
	 * @method bool hasGeo()
	 * @method bool isGeoFilled()
	 * @method bool isGeoChanged()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo fillGeo()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation getCancellation()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation remindActualCancellation()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation requireCancellation()
	 * @method \Bitrix\StaffTrack\Model\Shift setCancellation(\Bitrix\StaffTrack\Model\EO_ShiftCancellation $object)
	 * @method \Bitrix\StaffTrack\Model\Shift resetCancellation()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetCancellation()
	 * @method bool hasCancellation()
	 * @method bool isCancellationFilled()
	 * @method bool isCancellationChanged()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation fillCancellation()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo getGeoInner()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo remindActualGeoInner()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo requireGeoInner()
	 * @method \Bitrix\StaffTrack\Model\Shift setGeoInner(\Bitrix\StaffTrack\Model\EO_ShiftGeo $object)
	 * @method \Bitrix\StaffTrack\Model\Shift resetGeoInner()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetGeoInner()
	 * @method bool hasGeoInner()
	 * @method bool isGeoInnerFilled()
	 * @method bool isGeoInnerChanged()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo fillGeoInner()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection getMessages()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection requireMessages()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection fillMessages()
	 * @method bool hasMessages()
	 * @method bool isMessagesFilled()
	 * @method bool isMessagesChanged()
	 * @method void addToMessages(\Bitrix\StaffTrack\Model\EO_ShiftMessage $shiftMessage)
	 * @method void removeFromMessages(\Bitrix\StaffTrack\Model\EO_ShiftMessage $shiftMessage)
	 * @method void removeAllMessages()
	 * @method \Bitrix\StaffTrack\Model\Shift resetMessages()
	 * @method \Bitrix\StaffTrack\Model\Shift unsetMessages()
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
	 * @method \Bitrix\StaffTrack\Model\Shift set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Model\Shift reset($fieldName)
	 * @method \Bitrix\StaffTrack\Model\Shift unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Model\Shift wakeUp($data)
	 */
	class EO_Shift extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Model\ShiftTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\ShiftTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * ShiftCollection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\Date[] getShiftDateList()
	 * @method \Bitrix\Main\Type\Date[] fillShiftDate()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method \string[] getLocationList()
	 * @method \string[] fillLocation()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo[] getGeoList()
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection getGeoCollection()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection fillGeo()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation[] getCancellationList()
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection getCancellationCollection()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftCancellation_Collection fillCancellation()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo[] getGeoInnerList()
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection getGeoInnerCollection()
	 * @method \Bitrix\StaffTrack\Model\EO_ShiftGeo_Collection fillGeoInner()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection[] getMessagesList()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection getMessagesCollection()
	 * @method \Bitrix\StaffTrack\Model\ShiftMessageCollection fillMessages()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Model\Shift $object)
	 * @method bool has(\Bitrix\StaffTrack\Model\Shift $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\Shift getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\Shift[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Model\Shift $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Model\ShiftCollection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Model\Shift current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection merge(?\Bitrix\StaffTrack\Model\ShiftCollection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Model\Shift|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection filter(callable $callback)
	 */
	class EO_Shift_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Model\ShiftTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\ShiftTable';
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Shift_Result exec()
	 * @method \Bitrix\StaffTrack\Model\Shift fetchObject()
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection fetchCollection()
	 */
	class EO_Shift_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Model\Shift fetchObject()
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection fetchCollection()
	 */
	class EO_Shift_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Model\Shift createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection createCollection()
	 * @method \Bitrix\StaffTrack\Model\Shift wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Model\ShiftCollection wakeUpCollection($rows)
	 */
	class EO_Shift_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Model\CounterTable:stafftrack/lib/Model/CounterTable.php */
namespace Bitrix\StaffTrack\Model {
	/**
	 * Counter
	 * @see \Bitrix\StaffTrack\Model\CounterTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Model\Counter setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\StaffTrack\Model\Counter setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\StaffTrack\Model\Counter resetUserId()
	 * @method \Bitrix\StaffTrack\Model\Counter unsetUserId()
	 * @method \int fillUserId()
	 * @method \int getMuteStatus()
	 * @method \Bitrix\StaffTrack\Model\Counter setMuteStatus(\int|\Bitrix\Main\DB\SqlExpression $muteStatus)
	 * @method bool hasMuteStatus()
	 * @method bool isMuteStatusFilled()
	 * @method bool isMuteStatusChanged()
	 * @method \int remindActualMuteStatus()
	 * @method \int requireMuteStatus()
	 * @method \Bitrix\StaffTrack\Model\Counter resetMuteStatus()
	 * @method \Bitrix\StaffTrack\Model\Counter unsetMuteStatus()
	 * @method \int fillMuteStatus()
	 * @method \Bitrix\Main\Type\DateTime getMuteUntil()
	 * @method \Bitrix\StaffTrack\Model\Counter setMuteUntil(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $muteUntil)
	 * @method bool hasMuteUntil()
	 * @method bool isMuteUntilFilled()
	 * @method bool isMuteUntilChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualMuteUntil()
	 * @method \Bitrix\Main\Type\DateTime requireMuteUntil()
	 * @method \Bitrix\StaffTrack\Model\Counter resetMuteUntil()
	 * @method \Bitrix\StaffTrack\Model\Counter unsetMuteUntil()
	 * @method \Bitrix\Main\Type\DateTime fillMuteUntil()
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
	 * @method \Bitrix\StaffTrack\Model\Counter set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Model\Counter reset($fieldName)
	 * @method \Bitrix\StaffTrack\Model\Counter unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Model\Counter wakeUp($data)
	 */
	class EO_Counter extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Model\CounterTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\CounterTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_Counter_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \int[] getMuteStatusList()
	 * @method \int[] fillMuteStatus()
	 * @method \Bitrix\Main\Type\DateTime[] getMuteUntilList()
	 * @method \Bitrix\Main\Type\DateTime[] fillMuteUntil()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Model\Counter $object)
	 * @method bool has(\Bitrix\StaffTrack\Model\Counter $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\Counter getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\Counter[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Model\Counter $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Model\EO_Counter_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Model\Counter current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Model\EO_Counter_Collection merge(?\Bitrix\StaffTrack\Model\EO_Counter_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Model\Counter|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Model\EO_Counter_Collection filter(callable $callback)
	 */
	class EO_Counter_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Model\CounterTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\CounterTable';
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Counter_Result exec()
	 * @method \Bitrix\StaffTrack\Model\Counter fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_Counter_Collection fetchCollection()
	 */
	class EO_Counter_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Model\Counter fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_Counter_Collection fetchCollection()
	 */
	class EO_Counter_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Model\Counter createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Model\EO_Counter_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Model\Counter wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Model\EO_Counter_Collection wakeUpCollection($rows)
	 */
	class EO_Counter_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\StaffTrack\Model\HandledChatTable:stafftrack/lib/model/handledchattable.php */
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_HandledChat
	 * @see \Bitrix\StaffTrack\Model\HandledChatTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getChatId()
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat setChatId(\int|\Bitrix\Main\DB\SqlExpression $chatId)
	 * @method bool hasChatId()
	 * @method bool isChatIdFilled()
	 * @method bool isChatIdChanged()
	 * @method \int remindActualChatId()
	 * @method \int requireChatId()
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat resetChatId()
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat unsetChatId()
	 * @method \int fillChatId()
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
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat set($fieldName, $value)
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat reset($fieldName)
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\StaffTrack\Model\EO_HandledChat wakeUp($data)
	 */
	class EO_HandledChat extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\StaffTrack\Model\HandledChatTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\HandledChatTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * EO_HandledChat_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getChatIdList()
	 * @method \int[] fillChatId()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\StaffTrack\Model\EO_HandledChat $object)
	 * @method bool has(\Bitrix\StaffTrack\Model\EO_HandledChat $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat getByPrimary($primary)
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat[] getAll()
	 * @method bool remove(\Bitrix\StaffTrack\Model\EO_HandledChat $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\StaffTrack\Model\EO_HandledChat_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat_Collection merge(?\Bitrix\StaffTrack\Model\EO_HandledChat_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat|null find(callable $callback)
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat_Collection filter(callable $callback)
	 */
	class EO_HandledChat_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\StaffTrack\Model\HandledChatTable */
		static public $dataClass = '\Bitrix\StaffTrack\Model\HandledChatTable';
	}
}
namespace Bitrix\StaffTrack\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_HandledChat_Result exec()
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat_Collection fetchCollection()
	 */
	class EO_HandledChat_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat fetchObject()
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat_Collection fetchCollection()
	 */
	class EO_HandledChat_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat createObject($setDefaultValues = true)
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat_Collection createCollection()
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat wakeUpObject($row)
	 * @method \Bitrix\StaffTrack\Model\EO_HandledChat_Collection wakeUpCollection($rows)
	 */
	class EO_HandledChat_Entity extends \Bitrix\Main\ORM\Entity {}
}