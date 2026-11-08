<?php

class CSearchFullText
{
	/**
	 * @var CSearchFullText|null
	 */
	protected static $instance = null;

	protected $config = [];

	public function __construct($config = [])
	{
		$this->config = $config;
	}

	/**
	 * Returns current instance of the full text indexer.
	 *
	 * @return CSearchFullText
	 */
	public static function getInstance()
	{
		if (!isset(static::$instance))
		{
			$full_text_engine = COption::GetOptionString('search', 'full_text_engine');
			if ($full_text_engine === 'sphinx')
			{
				self::$instance = new CSearchSphinx([
					'connection' => COption::GetOptionString('search', 'sphinx_connection'),
					'index_name' => COption::GetOptionString('search', 'sphinx_index_name'),
				]);
			}
			elseif ($full_text_engine === 'opensearch')
			{
				require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/classes/general/backup.php';
				self::$instance = new CSearchOpenSearch([
					'connection' => COption::GetOptionString('search', 'opensearch_connection'),
					'user' => COption::GetOptionString('search', 'opensearch_user'),
					'password' => CPasswordStorage::Get('search@opensearch_password'),
					'index_name' => CPageOption::GetOptionString('search', 'opensearch_index') ?: COption::GetOptionString('search', 'opensearch_index'),
				]);
			}
			elseif ($full_text_engine === 'mysql')
			{
				self::$instance = new CSearchMysql;
			}
			elseif ($full_text_engine === 'pgsql')
			{
				self::$instance = new CSearchPgsql;
			}
			else
			{
				self::$instance = new CSearchStemTable();
			}
		}

		return static::$instance;
	}

	public function connect($ignoreErrors = false)
	{
		return true;
	}

	public function truncate()
	{
	}

	public function deleteById($ID)
	{
	}

	public function replace($ID, $arFields)
	{
	}

	public function update($ID, $arFields)
	{
	}

	public function syncPermission($permission, $arFilter)
	{
	}

	public function syncContent($id)
	{
		return false;
	}

	public function search($arParams, $aSort, $aParamsEx, $bTagsCloud)
	{
		return false;
	}

	public function searchTitle($phrase = '', $arPhrase = [], $nTopCount = 5, $arParams = [], $bNotFilter = false, $order = '')
	{
		return false;
	}

	public function getErrorText()
	{
		return '';
	}

	public function getErrorNumber()
	{
		return 0;
	}

	public function getRowFormatter()
	{
		return null;
	}
}

class CSearchFormatter
{
	public function format($r)
	{
		return $r;
	}
}
