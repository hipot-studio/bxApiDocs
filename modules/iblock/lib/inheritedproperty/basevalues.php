<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage iblock
 */
namespace Bitrix\Iblock\InheritedProperty;

abstract class BaseValues
{
	/** @var integer */
	protected $iblockId = null;

	/** @var array[string][string]string */
	protected $values = false;

	/** @var ValuesQueue */
	protected $queue = null;

	/**
	 * @param integer $iblockId Iblock identifier.
	 */
	public function __construct($iblockId)
	{
		$this->iblockId = (int)$iblockId;
		$this->queue = ValuesQueue::getInstance(get_called_class());
	}

	/**
	 * Returns the identifier of the iblock of the entity.
	 *
	 * @return integer
	 */
	public function getIblockId()
	{
		return $this->iblockId;
	}

	/**
	 * Returns the table name where values will be stored.
	 *
	 * @return string
	 */
	public function getValueTableName()
	{
		return "";
	}

	/**
	 * Returns type of the entity which will be stored into DB.
	 *
	 * @return string
	 */
	abstract public function getType();

	/**
	 * Returns unique identifier of the entity
	 *
	 * @return integer
	 */
	abstract public function getId();

	/**
	 * Creates an entity which will be used to process the templates.
	 *
	 * @return \Bitrix\Iblock\Template\Entity\Base
	 */
	abstract public function  createTemplateEntity();

	/**
	 * Returns all the parents of the entity.
	 *
	 * @return array[]\Bitrix\Iblock\InheritedProperty\BaseValues
	 */
	public function getParents()
	{
		return array();
	}

	/**
	 * Returns it's first parent if exists one.
	 * Otherwise returns null.
	 *
	 * @return \Bitrix\Iblock\InheritedProperty\BaseValues|null
	 */
	public function getParent()
	{
		$parents = $this->getParents();
		if (isset($parents[0]))
			return $parents[0];
		else
			return null;
	}

	/**
	 * Returns all calculated values of inherited properties
	 * for this entity.
	 *
	 * @return array[string]string
	 */
	public function getValues()
	{
		if ($this->values === false)
			$this->values = $this->queryValues();

		$result = array();
		foreach ($this->values as $CODE => $row)
		{
			$result[$CODE] = \Bitrix\Main\Text\HtmlFilter::encode(
				$row['VALUE'],
				ENT_COMPAT,
				false
			);
		}
		return $result;
	}

	/**
	 * Returns value of the inherited property.
	 * The result is html encoded string.
	 *
	 * @param string $propertyCode Mnemonic code.
	 * @return string
	 */
	public function getValue($propertyCode)
	{
		if ($this->values === false)
			$this->values = $this->queryValues();

		if (isset($this->values[$propertyCode]))
			return htmlspecialcharsEx($this->values[$propertyCode]["VALUE"]);
		else
			return "";
	}

	/**
	 * Queries templates for this entity.
	 * Then processes them in order to get
	 * calculated values.
	 *
	 * @return array[string][string]string
	 */
	public function queryValues()
	{
		$templateInstance = new BaseTemplate($this);
		$templates = $templateInstance->findTemplates();
		foreach ($templates as $CODE => $row)
		{
			$templates[$CODE]["VALUE"] = \Bitrix\Iblock\Template\Engine::process($this->createTemplateEntity(), $row["TEMPLATE"]);
		}
		return $templates;
	}

	/**
	 * Checks if there are some templates exists for this set of values.
	 *
	 * @return boolean
	 */
	public function hasTemplates()
	{
		$templateInstance = new BaseTemplate($this);
		return $templateInstance->hasTemplates($this);
	}

	/**
	 * Clears entity values DB cache
	 *
	 * @return void
	 */
	abstract function clearValues();

	/**
	 * Must be called on template delete.
	 *
	 * @param integer $ipropertyId Identifier of the inherited property.
	 *
	 * @return void
	 */
	public function deleteValues($ipropertyId)
	{
		$ipropertyId = (int)$ipropertyId;
		$connection = \Bitrix\Main\Application::getConnection();
		$connection->query("
			DELETE FROM b_iblock_iblock_iprop
			WHERE IPROP_ID = ".$ipropertyId."
		");
		$connection->query("
			DELETE FROM b_iblock_section_iprop
			WHERE IPROP_ID = ".$ipropertyId."
		");
		$connection->query("
			DELETE FROM b_iblock_element_iprop
			WHERE IPROP_ID = ".$ipropertyId."
		");
		ValuesQueue::deleteAll();
	}

	/**
	 * Helper method to save batch values.
	 *
	 * @param string $tableName Where to insert data.
	 * @param array  $primaryFields Primary fields list.
	 * @param array  $rows Data to insert.
	 *
	 * @return void
	 */
	protected function insertValues($tableName, $primaryFields, $rows)
	{
		$connection = \Bitrix\Main\Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		foreach ($sqlHelper->prepareMergeMultiple($tableName, $primaryFields, $rows) as $dml)
		{
			$connection->query($dml);
		}
	}

	/**
	 * Puts a mark for and element or section to be queried from the database.
	 *
	 * @param integer $iblockId IBlock identifier.
	 * @param integer $id Element or section identifier.
	 *
	 * @return void
	 */
	public static function queue($iblockId, $id)
	{
		ValuesQueue::getInstance(get_called_class())->addElement($iblockId, $id);
	}
}
