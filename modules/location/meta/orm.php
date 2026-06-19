<?php

/* ORMENTITYANNOTATION:Bitrix\Location\Model\RecentAddressTable:location/lib/model/recentaddresstable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_RecentAddress
	 * @see \Bitrix\Location\Model\RecentAddressTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Location\Model\EO_RecentAddress setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Location\Model\EO_RecentAddress setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Location\Model\EO_RecentAddress resetUserId()
	 * @method \Bitrix\Location\Model\EO_RecentAddress unsetUserId()
	 * @method \int fillUserId()
	 * @method \string getAddress()
	 * @method \Bitrix\Location\Model\EO_RecentAddress setAddress(\string|\Bitrix\Main\DB\SqlExpression $address)
	 * @method bool hasAddress()
	 * @method bool isAddressFilled()
	 * @method bool isAddressChanged()
	 * @method \string remindActualAddress()
	 * @method \string requireAddress()
	 * @method \Bitrix\Location\Model\EO_RecentAddress resetAddress()
	 * @method \Bitrix\Location\Model\EO_RecentAddress unsetAddress()
	 * @method \string fillAddress()
	 * @method \Bitrix\Main\Type\DateTime getUsedAt()
	 * @method \Bitrix\Location\Model\EO_RecentAddress setUsedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $usedAt)
	 * @method bool hasUsedAt()
	 * @method bool isUsedAtFilled()
	 * @method bool isUsedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUsedAt()
	 * @method \Bitrix\Main\Type\DateTime requireUsedAt()
	 * @method \Bitrix\Location\Model\EO_RecentAddress resetUsedAt()
	 * @method \Bitrix\Location\Model\EO_RecentAddress unsetUsedAt()
	 * @method \Bitrix\Main\Type\DateTime fillUsedAt()
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
	 * @method \Bitrix\Location\Model\EO_RecentAddress set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_RecentAddress reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_RecentAddress unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_RecentAddress wakeUp($data)
	 */
	class EO_RecentAddress extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\RecentAddressTable */
		static public $dataClass = '\Bitrix\Location\Model\RecentAddressTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_RecentAddress_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \string[] getAddressList()
	 * @method \string[] fillAddress()
	 * @method \Bitrix\Main\Type\DateTime[] getUsedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUsedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_RecentAddress $object)
	 * @method bool has(\Bitrix\Location\Model\EO_RecentAddress $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_RecentAddress getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_RecentAddress[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_RecentAddress $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_RecentAddress_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_RecentAddress current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_RecentAddress_Collection merge(?\Bitrix\Location\Model\EO_RecentAddress_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_RecentAddress|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_RecentAddress_Collection filter(callable $callback)
	 */
	class EO_RecentAddress_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\RecentAddressTable */
		static public $dataClass = '\Bitrix\Location\Model\RecentAddressTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RecentAddress_Result exec()
	 * @method \Bitrix\Location\Model\EO_RecentAddress fetchObject()
	 * @method \Bitrix\Location\Model\EO_RecentAddress_Collection fetchCollection()
	 */
	class EO_RecentAddress_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_RecentAddress fetchObject()
	 * @method \Bitrix\Location\Model\EO_RecentAddress_Collection fetchCollection()
	 */
	class EO_RecentAddress_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_RecentAddress createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_RecentAddress_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_RecentAddress wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_RecentAddress_Collection wakeUpCollection($rows)
	 */
	class EO_RecentAddress_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\AddressFieldTable:location/lib/model/addressfieldtable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_AddressField
	 * @see \Bitrix\Location\Model\AddressFieldTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getAddressId()
	 * @method \Bitrix\Location\Model\EO_AddressField setAddressId(\int|\Bitrix\Main\DB\SqlExpression $addressId)
	 * @method bool hasAddressId()
	 * @method bool isAddressIdFilled()
	 * @method bool isAddressIdChanged()
	 * @method \int getType()
	 * @method \Bitrix\Location\Model\EO_AddressField setType(\int|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string getValue()
	 * @method \Bitrix\Location\Model\EO_AddressField setValue(\string|\Bitrix\Main\DB\SqlExpression $value)
	 * @method bool hasValue()
	 * @method bool isValueFilled()
	 * @method bool isValueChanged()
	 * @method \string remindActualValue()
	 * @method \string requireValue()
	 * @method \Bitrix\Location\Model\EO_AddressField resetValue()
	 * @method \Bitrix\Location\Model\EO_AddressField unsetValue()
	 * @method \string fillValue()
	 * @method \string getValueNormalized()
	 * @method \Bitrix\Location\Model\EO_AddressField setValueNormalized(\string|\Bitrix\Main\DB\SqlExpression $valueNormalized)
	 * @method bool hasValueNormalized()
	 * @method bool isValueNormalizedFilled()
	 * @method bool isValueNormalizedChanged()
	 * @method \string remindActualValueNormalized()
	 * @method \string requireValueNormalized()
	 * @method \Bitrix\Location\Model\EO_AddressField resetValueNormalized()
	 * @method \Bitrix\Location\Model\EO_AddressField unsetValueNormalized()
	 * @method \string fillValueNormalized()
	 * @method \Bitrix\Location\Model\EO_Address getAddress()
	 * @method \Bitrix\Location\Model\EO_Address remindActualAddress()
	 * @method \Bitrix\Location\Model\EO_Address requireAddress()
	 * @method \Bitrix\Location\Model\EO_AddressField setAddress(\Bitrix\Location\Model\EO_Address $object)
	 * @method \Bitrix\Location\Model\EO_AddressField resetAddress()
	 * @method \Bitrix\Location\Model\EO_AddressField unsetAddress()
	 * @method bool hasAddress()
	 * @method bool isAddressFilled()
	 * @method bool isAddressChanged()
	 * @method \Bitrix\Location\Model\EO_Address fillAddress()
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
	 * @method \Bitrix\Location\Model\EO_AddressField set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_AddressField reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_AddressField unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_AddressField wakeUp($data)
	 */
	class EO_AddressField extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\AddressFieldTable */
		static public $dataClass = '\Bitrix\Location\Model\AddressFieldTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_AddressField_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getAddressIdList()
	 * @method \int[] getTypeList()
	 * @method \string[] getValueList()
	 * @method \string[] fillValue()
	 * @method \string[] getValueNormalizedList()
	 * @method \string[] fillValueNormalized()
	 * @method \Bitrix\Location\Model\EO_Address[] getAddressList()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection getAddressCollection()
	 * @method \Bitrix\Location\Model\EO_Address_Collection fillAddress()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_AddressField $object)
	 * @method bool has(\Bitrix\Location\Model\EO_AddressField $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_AddressField getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_AddressField[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_AddressField $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_AddressField_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_AddressField current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection merge(?\Bitrix\Location\Model\EO_AddressField_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_AddressField|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection filter(callable $callback)
	 */
	class EO_AddressField_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\AddressFieldTable */
		static public $dataClass = '\Bitrix\Location\Model\AddressFieldTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_AddressField_Result exec()
	 * @method \Bitrix\Location\Model\EO_AddressField fetchObject()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection fetchCollection()
	 */
	class EO_AddressField_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_AddressField fetchObject()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection fetchCollection()
	 */
	class EO_AddressField_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_AddressField createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_AddressField wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection wakeUpCollection($rows)
	 */
	class EO_AddressField_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\AddressLinkTable:location/lib/model/addresslinktable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_AddressLink
	 * @see \Bitrix\Location\Model\AddressLinkTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getAddressId()
	 * @method \Bitrix\Location\Model\EO_AddressLink setAddressId(\int|\Bitrix\Main\DB\SqlExpression $addressId)
	 * @method bool hasAddressId()
	 * @method bool isAddressIdFilled()
	 * @method bool isAddressIdChanged()
	 * @method \string getEntityId()
	 * @method \Bitrix\Location\Model\EO_AddressLink setEntityId(\string|\Bitrix\Main\DB\SqlExpression $entityId)
	 * @method bool hasEntityId()
	 * @method bool isEntityIdFilled()
	 * @method bool isEntityIdChanged()
	 * @method \string getEntityType()
	 * @method \Bitrix\Location\Model\EO_AddressLink setEntityType(\string|\Bitrix\Main\DB\SqlExpression $entityType)
	 * @method bool hasEntityType()
	 * @method bool isEntityTypeFilled()
	 * @method bool isEntityTypeChanged()
	 * @method \Bitrix\Location\Model\EO_Address getAddress()
	 * @method \Bitrix\Location\Model\EO_Address remindActualAddress()
	 * @method \Bitrix\Location\Model\EO_Address requireAddress()
	 * @method \Bitrix\Location\Model\EO_AddressLink setAddress(\Bitrix\Location\Model\EO_Address $object)
	 * @method \Bitrix\Location\Model\EO_AddressLink resetAddress()
	 * @method \Bitrix\Location\Model\EO_AddressLink unsetAddress()
	 * @method bool hasAddress()
	 * @method bool isAddressFilled()
	 * @method bool isAddressChanged()
	 * @method \Bitrix\Location\Model\EO_Address fillAddress()
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
	 * @method \Bitrix\Location\Model\EO_AddressLink set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_AddressLink reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_AddressLink unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_AddressLink wakeUp($data)
	 */
	class EO_AddressLink extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\AddressLinkTable */
		static public $dataClass = '\Bitrix\Location\Model\AddressLinkTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_AddressLink_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getAddressIdList()
	 * @method \string[] getEntityIdList()
	 * @method \string[] getEntityTypeList()
	 * @method \Bitrix\Location\Model\EO_Address[] getAddressList()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection getAddressCollection()
	 * @method \Bitrix\Location\Model\EO_Address_Collection fillAddress()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_AddressLink $object)
	 * @method bool has(\Bitrix\Location\Model\EO_AddressLink $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_AddressLink getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_AddressLink[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_AddressLink $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_AddressLink_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_AddressLink current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection merge(?\Bitrix\Location\Model\EO_AddressLink_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_AddressLink|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection filter(callable $callback)
	 */
	class EO_AddressLink_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\AddressLinkTable */
		static public $dataClass = '\Bitrix\Location\Model\AddressLinkTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_AddressLink_Result exec()
	 * @method \Bitrix\Location\Model\EO_AddressLink fetchObject()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection fetchCollection()
	 */
	class EO_AddressLink_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_AddressLink fetchObject()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection fetchCollection()
	 */
	class EO_AddressLink_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_AddressLink createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_AddressLink wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection wakeUpCollection($rows)
	 */
	class EO_AddressLink_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\LocationNameTable:location/lib/model/locationnametable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_LocationName
	 * @see \Bitrix\Location\Model\LocationNameTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getLocationId()
	 * @method \Bitrix\Location\Model\EO_LocationName setLocationId(\int|\Bitrix\Main\DB\SqlExpression $locationId)
	 * @method bool hasLocationId()
	 * @method bool isLocationIdFilled()
	 * @method bool isLocationIdChanged()
	 * @method \string getLanguageId()
	 * @method \Bitrix\Location\Model\EO_LocationName setLanguageId(\string|\Bitrix\Main\DB\SqlExpression $languageId)
	 * @method bool hasLanguageId()
	 * @method bool isLanguageIdFilled()
	 * @method bool isLanguageIdChanged()
	 * @method \string getName()
	 * @method \Bitrix\Location\Model\EO_LocationName setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Location\Model\EO_LocationName resetName()
	 * @method \Bitrix\Location\Model\EO_LocationName unsetName()
	 * @method \string fillName()
	 * @method \string getNameNormalized()
	 * @method \Bitrix\Location\Model\EO_LocationName setNameNormalized(\string|\Bitrix\Main\DB\SqlExpression $nameNormalized)
	 * @method bool hasNameNormalized()
	 * @method bool isNameNormalizedFilled()
	 * @method bool isNameNormalizedChanged()
	 * @method \string remindActualNameNormalized()
	 * @method \string requireNameNormalized()
	 * @method \Bitrix\Location\Model\EO_LocationName resetNameNormalized()
	 * @method \Bitrix\Location\Model\EO_LocationName unsetNameNormalized()
	 * @method \string fillNameNormalized()
	 * @method \Bitrix\Location\Model\EO_Location getLocation()
	 * @method \Bitrix\Location\Model\EO_Location remindActualLocation()
	 * @method \Bitrix\Location\Model\EO_Location requireLocation()
	 * @method \Bitrix\Location\Model\EO_LocationName setLocation(\Bitrix\Location\Model\EO_Location $object)
	 * @method \Bitrix\Location\Model\EO_LocationName resetLocation()
	 * @method \Bitrix\Location\Model\EO_LocationName unsetLocation()
	 * @method bool hasLocation()
	 * @method bool isLocationFilled()
	 * @method bool isLocationChanged()
	 * @method \Bitrix\Location\Model\EO_Location fillLocation()
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
	 * @method \Bitrix\Location\Model\EO_LocationName set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_LocationName reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_LocationName unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_LocationName wakeUp($data)
	 */
	class EO_LocationName extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\LocationNameTable */
		static public $dataClass = '\Bitrix\Location\Model\LocationNameTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_LocationName_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getLocationIdList()
	 * @method \string[] getLanguageIdList()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getNameNormalizedList()
	 * @method \string[] fillNameNormalized()
	 * @method \Bitrix\Location\Model\EO_Location[] getLocationList()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection getLocationCollection()
	 * @method \Bitrix\Location\Model\EO_Location_Collection fillLocation()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_LocationName $object)
	 * @method bool has(\Bitrix\Location\Model\EO_LocationName $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_LocationName getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_LocationName[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_LocationName $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_LocationName_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_LocationName current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection merge(?\Bitrix\Location\Model\EO_LocationName_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_LocationName|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection filter(callable $callback)
	 */
	class EO_LocationName_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\LocationNameTable */
		static public $dataClass = '\Bitrix\Location\Model\LocationNameTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_LocationName_Result exec()
	 * @method \Bitrix\Location\Model\EO_LocationName fetchObject()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection fetchCollection()
	 */
	class EO_LocationName_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_LocationName fetchObject()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection fetchCollection()
	 */
	class EO_LocationName_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_LocationName createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_LocationName wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection wakeUpCollection($rows)
	 */
	class EO_LocationName_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\StaticMapFileTable:location/lib/model/staticmapfiletable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_StaticMapFile
	 * @see \Bitrix\Location\Model\StaticMapFileTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getHash()
	 * @method \Bitrix\Location\Model\EO_StaticMapFile setHash(\string|\Bitrix\Main\DB\SqlExpression $hash)
	 * @method bool hasHash()
	 * @method bool isHashFilled()
	 * @method bool isHashChanged()
	 * @method \int getFileId()
	 * @method \Bitrix\Location\Model\EO_StaticMapFile setFileId(\int|\Bitrix\Main\DB\SqlExpression $fileId)
	 * @method bool hasFileId()
	 * @method bool isFileIdFilled()
	 * @method bool isFileIdChanged()
	 * @method \int remindActualFileId()
	 * @method \int requireFileId()
	 * @method \Bitrix\Location\Model\EO_StaticMapFile resetFileId()
	 * @method \Bitrix\Location\Model\EO_StaticMapFile unsetFileId()
	 * @method \int fillFileId()
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
	 * @method \Bitrix\Location\Model\EO_StaticMapFile set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_StaticMapFile reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_StaticMapFile unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_StaticMapFile wakeUp($data)
	 */
	class EO_StaticMapFile extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\StaticMapFileTable */
		static public $dataClass = '\Bitrix\Location\Model\StaticMapFileTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_StaticMapFile_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getHashList()
	 * @method \int[] getFileIdList()
	 * @method \int[] fillFileId()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_StaticMapFile $object)
	 * @method bool has(\Bitrix\Location\Model\EO_StaticMapFile $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_StaticMapFile getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_StaticMapFile[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_StaticMapFile $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_StaticMapFile_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_StaticMapFile current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_StaticMapFile_Collection merge(?\Bitrix\Location\Model\EO_StaticMapFile_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_StaticMapFile|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_StaticMapFile_Collection filter(callable $callback)
	 */
	class EO_StaticMapFile_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\StaticMapFileTable */
		static public $dataClass = '\Bitrix\Location\Model\StaticMapFileTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_StaticMapFile_Result exec()
	 * @method \Bitrix\Location\Model\EO_StaticMapFile fetchObject()
	 * @method \Bitrix\Location\Model\EO_StaticMapFile_Collection fetchCollection()
	 */
	class EO_StaticMapFile_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_StaticMapFile fetchObject()
	 * @method \Bitrix\Location\Model\EO_StaticMapFile_Collection fetchCollection()
	 */
	class EO_StaticMapFile_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_StaticMapFile createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_StaticMapFile_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_StaticMapFile wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_StaticMapFile_Collection wakeUpCollection($rows)
	 */
	class EO_StaticMapFile_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\LocationTable:location/lib/model/locationtable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_Location
	 * @see \Bitrix\Location\Model\LocationTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Location\Model\EO_Location setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getCode()
	 * @method \Bitrix\Location\Model\EO_Location setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Location\Model\EO_Location resetCode()
	 * @method \Bitrix\Location\Model\EO_Location unsetCode()
	 * @method \string fillCode()
	 * @method \string getExternalId()
	 * @method \Bitrix\Location\Model\EO_Location setExternalId(\string|\Bitrix\Main\DB\SqlExpression $externalId)
	 * @method bool hasExternalId()
	 * @method bool isExternalIdFilled()
	 * @method bool isExternalIdChanged()
	 * @method \string remindActualExternalId()
	 * @method \string requireExternalId()
	 * @method \Bitrix\Location\Model\EO_Location resetExternalId()
	 * @method \Bitrix\Location\Model\EO_Location unsetExternalId()
	 * @method \string fillExternalId()
	 * @method \string getSourceCode()
	 * @method \Bitrix\Location\Model\EO_Location setSourceCode(\string|\Bitrix\Main\DB\SqlExpression $sourceCode)
	 * @method bool hasSourceCode()
	 * @method bool isSourceCodeFilled()
	 * @method bool isSourceCodeChanged()
	 * @method \string remindActualSourceCode()
	 * @method \string requireSourceCode()
	 * @method \Bitrix\Location\Model\EO_Location resetSourceCode()
	 * @method \Bitrix\Location\Model\EO_Location unsetSourceCode()
	 * @method \string fillSourceCode()
	 * @method \float getLatitude()
	 * @method \Bitrix\Location\Model\EO_Location setLatitude(\float|\Bitrix\Main\DB\SqlExpression $latitude)
	 * @method bool hasLatitude()
	 * @method bool isLatitudeFilled()
	 * @method bool isLatitudeChanged()
	 * @method \float remindActualLatitude()
	 * @method \float requireLatitude()
	 * @method \Bitrix\Location\Model\EO_Location resetLatitude()
	 * @method \Bitrix\Location\Model\EO_Location unsetLatitude()
	 * @method \float fillLatitude()
	 * @method \float getLongitude()
	 * @method \Bitrix\Location\Model\EO_Location setLongitude(\float|\Bitrix\Main\DB\SqlExpression $longitude)
	 * @method bool hasLongitude()
	 * @method bool isLongitudeFilled()
	 * @method bool isLongitudeChanged()
	 * @method \float remindActualLongitude()
	 * @method \float requireLongitude()
	 * @method \Bitrix\Location\Model\EO_Location resetLongitude()
	 * @method \Bitrix\Location\Model\EO_Location unsetLongitude()
	 * @method \float fillLongitude()
	 * @method \Bitrix\Main\Type\DateTime getTimestampX()
	 * @method \Bitrix\Location\Model\EO_Location setTimestampX(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $timestampX)
	 * @method bool hasTimestampX()
	 * @method bool isTimestampXFilled()
	 * @method bool isTimestampXChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualTimestampX()
	 * @method \Bitrix\Main\Type\DateTime requireTimestampX()
	 * @method \Bitrix\Location\Model\EO_Location resetTimestampX()
	 * @method \Bitrix\Location\Model\EO_Location unsetTimestampX()
	 * @method \Bitrix\Main\Type\DateTime fillTimestampX()
	 * @method \int getType()
	 * @method \Bitrix\Location\Model\EO_Location setType(\int|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \int remindActualType()
	 * @method \int requireType()
	 * @method \Bitrix\Location\Model\EO_Location resetType()
	 * @method \Bitrix\Location\Model\EO_Location unsetType()
	 * @method \int fillType()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection getName()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection requireName()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection fillName()
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method void addToName(\Bitrix\Location\Model\EO_LocationName $locationName)
	 * @method void removeFromName(\Bitrix\Location\Model\EO_LocationName $locationName)
	 * @method void removeAllName()
	 * @method \Bitrix\Location\Model\EO_Location resetName()
	 * @method \Bitrix\Location\Model\EO_Location unsetName()
	 * @method \Bitrix\Location\Model\EO_Address_Collection getAddresses()
	 * @method \Bitrix\Location\Model\EO_Address_Collection requireAddresses()
	 * @method \Bitrix\Location\Model\EO_Address_Collection fillAddresses()
	 * @method bool hasAddresses()
	 * @method bool isAddressesFilled()
	 * @method bool isAddressesChanged()
	 * @method void addToAddresses(\Bitrix\Location\Model\EO_Address $address)
	 * @method void removeFromAddresses(\Bitrix\Location\Model\EO_Address $address)
	 * @method void removeAllAddresses()
	 * @method \Bitrix\Location\Model\EO_Location resetAddresses()
	 * @method \Bitrix\Location\Model\EO_Location unsetAddresses()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection getFields()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection requireFields()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection fillFields()
	 * @method bool hasFields()
	 * @method bool isFieldsFilled()
	 * @method bool isFieldsChanged()
	 * @method void addToFields(\Bitrix\Location\Model\EO_LocationField $locationField)
	 * @method void removeFromFields(\Bitrix\Location\Model\EO_LocationField $locationField)
	 * @method void removeAllFields()
	 * @method \Bitrix\Location\Model\EO_Location resetFields()
	 * @method \Bitrix\Location\Model\EO_Location unsetFields()
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
	 * @method \Bitrix\Location\Model\EO_Location set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_Location reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_Location unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_Location wakeUp($data)
	 */
	class EO_Location extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\LocationTable */
		static public $dataClass = '\Bitrix\Location\Model\LocationTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_Location_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getExternalIdList()
	 * @method \string[] fillExternalId()
	 * @method \string[] getSourceCodeList()
	 * @method \string[] fillSourceCode()
	 * @method \float[] getLatitudeList()
	 * @method \float[] fillLatitude()
	 * @method \float[] getLongitudeList()
	 * @method \float[] fillLongitude()
	 * @method \Bitrix\Main\Type\DateTime[] getTimestampXList()
	 * @method \Bitrix\Main\Type\DateTime[] fillTimestampX()
	 * @method \int[] getTypeList()
	 * @method \int[] fillType()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection[] getNameList()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection getNameCollection()
	 * @method \Bitrix\Location\Model\EO_LocationName_Collection fillName()
	 * @method \Bitrix\Location\Model\EO_Address_Collection[] getAddressesList()
	 * @method \Bitrix\Location\Model\EO_Address_Collection getAddressesCollection()
	 * @method \Bitrix\Location\Model\EO_Address_Collection fillAddresses()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection[] getFieldsList()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection getFieldsCollection()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection fillFields()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_Location $object)
	 * @method bool has(\Bitrix\Location\Model\EO_Location $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_Location getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_Location[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_Location $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_Location_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_Location current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_Location_Collection merge(?\Bitrix\Location\Model\EO_Location_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_Location|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_Location_Collection filter(callable $callback)
	 */
	class EO_Location_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\LocationTable */
		static public $dataClass = '\Bitrix\Location\Model\LocationTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Location_Result exec()
	 * @method \Bitrix\Location\Model\EO_Location fetchObject()
	 * @method \Bitrix\Location\Model\EO_Location_Collection fetchCollection()
	 */
	class EO_Location_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_Location fetchObject()
	 * @method \Bitrix\Location\Model\EO_Location_Collection fetchCollection()
	 */
	class EO_Location_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_Location createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_Location_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_Location wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_Location_Collection wakeUpCollection($rows)
	 */
	class EO_Location_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\LocationFieldTable:location/lib/model/locationfieldtable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_LocationField
	 * @see \Bitrix\Location\Model\LocationFieldTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getLocationId()
	 * @method \Bitrix\Location\Model\EO_LocationField setLocationId(\int|\Bitrix\Main\DB\SqlExpression $locationId)
	 * @method bool hasLocationId()
	 * @method bool isLocationIdFilled()
	 * @method bool isLocationIdChanged()
	 * @method \int getType()
	 * @method \Bitrix\Location\Model\EO_LocationField setType(\int|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string getValue()
	 * @method \Bitrix\Location\Model\EO_LocationField setValue(\string|\Bitrix\Main\DB\SqlExpression $value)
	 * @method bool hasValue()
	 * @method bool isValueFilled()
	 * @method bool isValueChanged()
	 * @method \string remindActualValue()
	 * @method \string requireValue()
	 * @method \Bitrix\Location\Model\EO_LocationField resetValue()
	 * @method \Bitrix\Location\Model\EO_LocationField unsetValue()
	 * @method \string fillValue()
	 * @method \Bitrix\Location\Model\EO_Location getLocation()
	 * @method \Bitrix\Location\Model\EO_Location remindActualLocation()
	 * @method \Bitrix\Location\Model\EO_Location requireLocation()
	 * @method \Bitrix\Location\Model\EO_LocationField setLocation(\Bitrix\Location\Model\EO_Location $object)
	 * @method \Bitrix\Location\Model\EO_LocationField resetLocation()
	 * @method \Bitrix\Location\Model\EO_LocationField unsetLocation()
	 * @method bool hasLocation()
	 * @method bool isLocationFilled()
	 * @method bool isLocationChanged()
	 * @method \Bitrix\Location\Model\EO_Location fillLocation()
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
	 * @method \Bitrix\Location\Model\EO_LocationField set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_LocationField reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_LocationField unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_LocationField wakeUp($data)
	 */
	class EO_LocationField extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\LocationFieldTable */
		static public $dataClass = '\Bitrix\Location\Model\LocationFieldTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_LocationField_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getLocationIdList()
	 * @method \int[] getTypeList()
	 * @method \string[] getValueList()
	 * @method \string[] fillValue()
	 * @method \Bitrix\Location\Model\EO_Location[] getLocationList()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection getLocationCollection()
	 * @method \Bitrix\Location\Model\EO_Location_Collection fillLocation()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_LocationField $object)
	 * @method bool has(\Bitrix\Location\Model\EO_LocationField $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_LocationField getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_LocationField[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_LocationField $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_LocationField_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_LocationField current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection merge(?\Bitrix\Location\Model\EO_LocationField_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_LocationField|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection filter(callable $callback)
	 */
	class EO_LocationField_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\LocationFieldTable */
		static public $dataClass = '\Bitrix\Location\Model\LocationFieldTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_LocationField_Result exec()
	 * @method \Bitrix\Location\Model\EO_LocationField fetchObject()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection fetchCollection()
	 */
	class EO_LocationField_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_LocationField fetchObject()
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection fetchCollection()
	 */
	class EO_LocationField_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_LocationField createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_LocationField wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_LocationField_Collection wakeUpCollection($rows)
	 */
	class EO_LocationField_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\AreaTable:location/lib/model/areatable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_Area
	 * @see \Bitrix\Location\Model\AreaTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Location\Model\EO_Area setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getType()
	 * @method \Bitrix\Location\Model\EO_Area setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Location\Model\EO_Area resetType()
	 * @method \Bitrix\Location\Model\EO_Area unsetType()
	 * @method \string fillType()
	 * @method \string getCode()
	 * @method \Bitrix\Location\Model\EO_Area setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Location\Model\EO_Area resetCode()
	 * @method \Bitrix\Location\Model\EO_Area unsetCode()
	 * @method \string fillCode()
	 * @method \int getSort()
	 * @method \Bitrix\Location\Model\EO_Area setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Location\Model\EO_Area resetSort()
	 * @method \Bitrix\Location\Model\EO_Area unsetSort()
	 * @method \int fillSort()
	 * @method \string getGeometry()
	 * @method \Bitrix\Location\Model\EO_Area setGeometry(\string|\Bitrix\Main\DB\SqlExpression $geometry)
	 * @method bool hasGeometry()
	 * @method bool isGeometryFilled()
	 * @method bool isGeometryChanged()
	 * @method \string remindActualGeometry()
	 * @method \string requireGeometry()
	 * @method \Bitrix\Location\Model\EO_Area resetGeometry()
	 * @method \Bitrix\Location\Model\EO_Area unsetGeometry()
	 * @method \string fillGeometry()
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
	 * @method \Bitrix\Location\Model\EO_Area set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_Area reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_Area unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_Area wakeUp($data)
	 */
	class EO_Area extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\AreaTable */
		static public $dataClass = '\Bitrix\Location\Model\AreaTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_Area_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method \string[] getGeometryList()
	 * @method \string[] fillGeometry()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_Area $object)
	 * @method bool has(\Bitrix\Location\Model\EO_Area $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_Area getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_Area[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_Area $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_Area_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_Area current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_Area_Collection merge(?\Bitrix\Location\Model\EO_Area_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_Area|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_Area_Collection filter(callable $callback)
	 */
	class EO_Area_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\AreaTable */
		static public $dataClass = '\Bitrix\Location\Model\AreaTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Area_Result exec()
	 * @method \Bitrix\Location\Model\EO_Area fetchObject()
	 * @method \Bitrix\Location\Model\EO_Area_Collection fetchCollection()
	 */
	class EO_Area_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_Area fetchObject()
	 * @method \Bitrix\Location\Model\EO_Area_Collection fetchCollection()
	 */
	class EO_Area_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_Area createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_Area_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_Area wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_Area_Collection wakeUpCollection($rows)
	 */
	class EO_Area_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\AddressTable:location/lib/model/addresstable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_Address
	 * @see \Bitrix\Location\Model\AddressTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Location\Model\EO_Address setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getLocationId()
	 * @method \Bitrix\Location\Model\EO_Address setLocationId(\int|\Bitrix\Main\DB\SqlExpression $locationId)
	 * @method bool hasLocationId()
	 * @method bool isLocationIdFilled()
	 * @method bool isLocationIdChanged()
	 * @method \int remindActualLocationId()
	 * @method \int requireLocationId()
	 * @method \Bitrix\Location\Model\EO_Address resetLocationId()
	 * @method \Bitrix\Location\Model\EO_Address unsetLocationId()
	 * @method \int fillLocationId()
	 * @method \string getLanguageId()
	 * @method \Bitrix\Location\Model\EO_Address setLanguageId(\string|\Bitrix\Main\DB\SqlExpression $languageId)
	 * @method bool hasLanguageId()
	 * @method bool isLanguageIdFilled()
	 * @method bool isLanguageIdChanged()
	 * @method \string remindActualLanguageId()
	 * @method \string requireLanguageId()
	 * @method \Bitrix\Location\Model\EO_Address resetLanguageId()
	 * @method \Bitrix\Location\Model\EO_Address unsetLanguageId()
	 * @method \string fillLanguageId()
	 * @method \float getLatitude()
	 * @method \Bitrix\Location\Model\EO_Address setLatitude(\float|\Bitrix\Main\DB\SqlExpression $latitude)
	 * @method bool hasLatitude()
	 * @method bool isLatitudeFilled()
	 * @method bool isLatitudeChanged()
	 * @method \float remindActualLatitude()
	 * @method \float requireLatitude()
	 * @method \Bitrix\Location\Model\EO_Address resetLatitude()
	 * @method \Bitrix\Location\Model\EO_Address unsetLatitude()
	 * @method \float fillLatitude()
	 * @method \float getLongitude()
	 * @method \Bitrix\Location\Model\EO_Address setLongitude(\float|\Bitrix\Main\DB\SqlExpression $longitude)
	 * @method bool hasLongitude()
	 * @method bool isLongitudeFilled()
	 * @method bool isLongitudeChanged()
	 * @method \float remindActualLongitude()
	 * @method \float requireLongitude()
	 * @method \Bitrix\Location\Model\EO_Address resetLongitude()
	 * @method \Bitrix\Location\Model\EO_Address unsetLongitude()
	 * @method \float fillLongitude()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection getFields()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection requireFields()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection fillFields()
	 * @method bool hasFields()
	 * @method bool isFieldsFilled()
	 * @method bool isFieldsChanged()
	 * @method void addToFields(\Bitrix\Location\Model\EO_AddressField $addressField)
	 * @method void removeFromFields(\Bitrix\Location\Model\EO_AddressField $addressField)
	 * @method void removeAllFields()
	 * @method \Bitrix\Location\Model\EO_Address resetFields()
	 * @method \Bitrix\Location\Model\EO_Address unsetFields()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection getLinks()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection requireLinks()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection fillLinks()
	 * @method bool hasLinks()
	 * @method bool isLinksFilled()
	 * @method bool isLinksChanged()
	 * @method void addToLinks(\Bitrix\Location\Model\EO_AddressLink $addressLink)
	 * @method void removeFromLinks(\Bitrix\Location\Model\EO_AddressLink $addressLink)
	 * @method void removeAllLinks()
	 * @method \Bitrix\Location\Model\EO_Address resetLinks()
	 * @method \Bitrix\Location\Model\EO_Address unsetLinks()
	 * @method \Bitrix\Location\Model\EO_Location getLocation()
	 * @method \Bitrix\Location\Model\EO_Location remindActualLocation()
	 * @method \Bitrix\Location\Model\EO_Location requireLocation()
	 * @method \Bitrix\Location\Model\EO_Address setLocation(\Bitrix\Location\Model\EO_Location $object)
	 * @method \Bitrix\Location\Model\EO_Address resetLocation()
	 * @method \Bitrix\Location\Model\EO_Address unsetLocation()
	 * @method bool hasLocation()
	 * @method bool isLocationFilled()
	 * @method bool isLocationChanged()
	 * @method \Bitrix\Location\Model\EO_Location fillLocation()
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
	 * @method \Bitrix\Location\Model\EO_Address set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_Address reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_Address unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_Address wakeUp($data)
	 */
	class EO_Address extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\AddressTable */
		static public $dataClass = '\Bitrix\Location\Model\AddressTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_Address_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getLocationIdList()
	 * @method \int[] fillLocationId()
	 * @method \string[] getLanguageIdList()
	 * @method \string[] fillLanguageId()
	 * @method \float[] getLatitudeList()
	 * @method \float[] fillLatitude()
	 * @method \float[] getLongitudeList()
	 * @method \float[] fillLongitude()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection[] getFieldsList()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection getFieldsCollection()
	 * @method \Bitrix\Location\Model\EO_AddressField_Collection fillFields()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection[] getLinksList()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection getLinksCollection()
	 * @method \Bitrix\Location\Model\EO_AddressLink_Collection fillLinks()
	 * @method \Bitrix\Location\Model\EO_Location[] getLocationList()
	 * @method \Bitrix\Location\Model\EO_Address_Collection getLocationCollection()
	 * @method \Bitrix\Location\Model\EO_Location_Collection fillLocation()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_Address $object)
	 * @method bool has(\Bitrix\Location\Model\EO_Address $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_Address getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_Address[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_Address $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_Address_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_Address current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_Address_Collection merge(?\Bitrix\Location\Model\EO_Address_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_Address|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_Address_Collection filter(callable $callback)
	 */
	class EO_Address_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\AddressTable */
		static public $dataClass = '\Bitrix\Location\Model\AddressTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Address_Result exec()
	 * @method \Bitrix\Location\Model\EO_Address fetchObject()
	 * @method \Bitrix\Location\Model\EO_Address_Collection fetchCollection()
	 */
	class EO_Address_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_Address fetchObject()
	 * @method \Bitrix\Location\Model\EO_Address_Collection fetchCollection()
	 */
	class EO_Address_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_Address createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_Address_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_Address wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_Address_Collection wakeUpCollection($rows)
	 */
	class EO_Address_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Location\Model\SourceTable:location/lib/model/sourcetable.php */
namespace Bitrix\Location\Model {
	/**
	 * EO_Source
	 * @see \Bitrix\Location\Model\SourceTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getCode()
	 * @method \Bitrix\Location\Model\EO_Source setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string getName()
	 * @method \Bitrix\Location\Model\EO_Source setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Location\Model\EO_Source resetName()
	 * @method \Bitrix\Location\Model\EO_Source unsetName()
	 * @method \string fillName()
	 * @method \string getConfig()
	 * @method \Bitrix\Location\Model\EO_Source setConfig(\string|\Bitrix\Main\DB\SqlExpression $config)
	 * @method bool hasConfig()
	 * @method bool isConfigFilled()
	 * @method bool isConfigChanged()
	 * @method \string remindActualConfig()
	 * @method \string requireConfig()
	 * @method \Bitrix\Location\Model\EO_Source resetConfig()
	 * @method \Bitrix\Location\Model\EO_Source unsetConfig()
	 * @method \string fillConfig()
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
	 * @method \Bitrix\Location\Model\EO_Source set($fieldName, $value)
	 * @method \Bitrix\Location\Model\EO_Source reset($fieldName)
	 * @method \Bitrix\Location\Model\EO_Source unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Location\Model\EO_Source wakeUp($data)
	 */
	class EO_Source extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Location\Model\SourceTable */
		static public $dataClass = '\Bitrix\Location\Model\SourceTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Location\Model {
	/**
	 * EO_Source_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getCodeList()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getConfigList()
	 * @method \string[] fillConfig()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Location\Model\EO_Source $object)
	 * @method bool has(\Bitrix\Location\Model\EO_Source $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_Source getByPrimary($primary)
	 * @method \Bitrix\Location\Model\EO_Source[] getAll()
	 * @method bool remove(\Bitrix\Location\Model\EO_Source $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Location\Model\EO_Source_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Location\Model\EO_Source current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Location\Model\EO_Source_Collection merge(?\Bitrix\Location\Model\EO_Source_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Location\Model\EO_Source|null find(callable $callback)
	 * @method \Bitrix\Location\Model\EO_Source_Collection filter(callable $callback)
	 */
	class EO_Source_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Location\Model\SourceTable */
		static public $dataClass = '\Bitrix\Location\Model\SourceTable';
	}
}
namespace Bitrix\Location\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Source_Result exec()
	 * @method \Bitrix\Location\Model\EO_Source fetchObject()
	 * @method \Bitrix\Location\Model\EO_Source_Collection fetchCollection()
	 */
	class EO_Source_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Location\Model\EO_Source fetchObject()
	 * @method \Bitrix\Location\Model\EO_Source_Collection fetchCollection()
	 */
	class EO_Source_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Location\Model\EO_Source createObject($setDefaultValues = true)
	 * @method \Bitrix\Location\Model\EO_Source_Collection createCollection()
	 * @method \Bitrix\Location\Model\EO_Source wakeUpObject($row)
	 * @method \Bitrix\Location\Model\EO_Source_Collection wakeUpCollection($rows)
	 */
	class EO_Source_Entity extends \Bitrix\Main\ORM\Entity {}
}