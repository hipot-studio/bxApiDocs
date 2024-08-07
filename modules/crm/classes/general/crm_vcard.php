<?php

IncludeModuleLangFile(__FILE__);

class CCrmVCard
{
	protected $arCard	= Array();
	protected $arEmail = Array();
	public $lastError = '';
	protected $version = null;

	function __construct()
	{
	}

	public function ReadCard($path = false, $data = null)
	{
		// read vCard
		$cardData = '';
		if ($path != false && is_null($data))
		{
			$fp = fopen ($path, 'r');
			$cardData = fread ($fp, filesize($path));
			fclose ($fp);
		}
		else if (!is_null($data))
		{
			$cardData = $data;
		}
		else
		{
			$this->lastError = GetMessage('CRM_VCARD_ERR_READ');
		}

		// split read data
		$arCard = preg_split('/(?:\r\n|\r|\n)/', $cardData);
		$arCardData = array();
		$i = 0;
		foreach($arCard as $value)
		{
			if (!empty($value))
			{
				if (!mb_strstr($value, ':'))
				{
					if ($i > 0)
						$arCardData[($i - 1)].= $value;
					else
						$this->lastError = GetMessage('CRM_VCARD_ERR_FORMAT');
				}
				else
				{
					$arCardData[$i] = $value;
					$i++;
				}
			}
		}

		$cardDataCnt = count($arCardData);
		if ($cardDataCnt < 3)
			$this->lastError = GetMessage('CRM_VCARD_ERR_FORMAT');
		else
		{
			// check begin vCard
			if ($this->GetParam($arCardData[0], 'TYPE') != 'BEGIN' ||
				$this->GetParam($arCardData[0], 'PARAM') ||
				mb_strtoupper($this->GetParam($arCardData[0], 'VALUE')) != 'VCARD')
			{
				$this->lastError = GetMessage('CRM_VCARD_ERR_FORMAT');
			}
			// check version vCard
			foreach($arCardData as $value)
			{
				if ($this->GetParam($value, 'TYPE') == 'VERSION')
				{
					$this->setVersion($this->GetParam($value, 'VALUE'));
					break;
				}
			}
			if (is_null($this->GetVersion()))
				$this->lastError = GetMessage('CRM_VCARD_ERR_FORMAT');
			// check end vCard
			if ($this->GetParam($arCardData[($cardDataCnt-1)], 'TYPE') != 'END' ||
				$this->GetParam($arCardData[($cardDataCnt-1)], 'PARAM') ||
				mb_strtoupper($this->GetParam($arCardData[($cardDataCnt - 1)], 'VALUE')) != 'VCARD')
			{
				$this->lastError = GetMessage('CRM_VCARD_ERR_FORMAT');
			}
		}

		if ($this->lastError == '')
		{
			$n = 0;
			foreach($arCardData as $value)
			{
				if ($this->GetParam($value, 'TYPE') == 'N')
				{
					$arName = explode(';', $this->GetParam($value, 'VALUE'));
					$this->arCard['NAME'] = isset($arName[1])? $arName[1]: '';
					$this->arCard['LAST_NAME'] = isset($arName[0])? $arName[0]: '';
					$this->arCard['SECOND_NAME'] = isset($arName[2])? $arName[2]: '';
				}
				if ($this->GetParam($value, 'TYPE') == 'ADR')
				{
					$labelValue = $this->GetParam($value, 'ADR');

					$arLabelValue = array();
					foreach(explode(';', $labelValue) as $value)
						if(!empty($value)) $arLabelValue[] = $value;

					if (isset($this->arCard['ADDRESS']))
						$this->arCard['ADDRESS'] .= ', '.implode(' ', $arLabelValue);
					else
						$this->arCard['ADDRESS'] = implode(' ', $arLabelValue);
				}
				if ($this->GetParam($value, 'TYPE') == 'TITLE')
				{
					$this->arCard['POST'] = $this->GetParam($value, 'VALUE');
				}
				if ($this->GetParam($value, 'TYPE') == 'ORG')
				{
					$this->arCard['COMPANY_TITLE'] = $this->GetParam($value, 'VALUE');
				}
				if ($this->GetParam($value, 'TYPE') == 'URL')
				{
					$this->arCard['FM']['WEB']['n'.$n++] = Array(
						'VALUE' => $this->GetParam($value, 'VALUE'),
						'VALUE_TYPE' => 'WORK'
					);
				}
				if ($this->GetParam($value, 'TYPE') == 'EMAIL')
				{

					$ar = explode(';', $this->GetParam($value, 'MIXED'));
					foreach($ar as $type)
					{
						if ($type == 'INTERNET')
						{
							$this->arCard['FM']['EMAIL']['n'.$n++] = Array(
								'VALUE' => $this->GetParam($value, 'VALUE'),
								'VALUE_TYPE' => 'WORK'
							);
							$this->arEmail[] = $this->GetParam($value, 'VALUE');
						}
					}
				}
				if ($this->GetParam($value, 'TYPE') == 'TEL')
				{
					$ar = explode(';', $this->GetParam($value, 'MIXED'));
					$phoneType = 'OTHER';
					foreach($ar as $type)
					{
						if ($type == 'WORK')
							$phoneType = 'WORK';
						else if ($type == 'CELL')
							$phoneType = 'MOBILE';
						else if ($type == 'FAX')
							$phoneType = 'FAX';
						else if ($type == 'HOME')
							$phoneType = 'HOME';
						else if ($type == 'PAGER')
							$phoneType = 'PAGER';
					}
					$this->arCard['FM']['PHONE']['n'.$n++] = Array(
						'VALUE' => $this->GetParam($value, 'VALUE'),
						'VALUE_TYPE' => $phoneType
					);
				}
				if ($this->GetParam($value, 'TYPE') == 'NOTE')
				{
					$this->arCard['COMMENTS'] = $this->GetParam($value, 'VALUE');
				}
			}
		}
		return $this->GetCard();
	}

	protected function GetParam($value, $type = '')
	{

		if(mb_strstr($value, ':'))
		{
			if($type == 'TYPE' ||
				$type == 'PARAM' ||
				$type == 'MIXED')
			{

				$value = mb_strtoupper(mb_substr($value, 0, mb_strpos($value, ':')));
				if($type == 'TYPE')
				{
					return (mb_strstr($value, ';'))? mb_substr($value, 0, mb_strpos($value, ';')) : trim($value);
				}
				else
				{
					if($type == 'PARAM')
					{
						return (mb_strstr($value, ';'))? str_replace(',', ';', str_replace('TYPE=', '', mb_substr(mb_strstr($value, ';'), 1))) : '';
					}
					else
					{
						return str_replace(',', ';', str_replace('TYPE=', '', $value));
					}
				}
				echo $type.'-'.$value;
			}
			else
			{
				$arParam = explode(';', $this->GetParam($value, 'PARAM'));
				foreach($arParam as $paramValue)
				{
					$arParamType = (mb_strstr($paramValue, '='))? mb_substr($paramValue, 0, mb_strpos($paramValue, '=')) : trim($paramValue);
					if($arParamType == 'CHARSET')
					{
						$arParamValue = (trim(mb_substr(mb_strstr($paramValue, '='), 1)));
						if(mb_strtoupper(LANG_CHARSET) != $arParamValue)
						{
							$value = \Bitrix\Main\Text\Encoding::convertEncoding($value, $arParamValue, LANG_CHARSET);
						}
					}
				}
				return (trim(mb_substr(mb_strstr($value, ':'), 1)));
			}
		}
		else
		{
			$this->lastError = GetMessage('CRM_VCARD_ERR_FORMAT');
			return false;
		}
	}

	public function SetVersion($version)
	{
		if ($version == '2.1' ||
			$version == '3.0')
			$this->version = $version;
		else
			$this->lastError = GetMessage('CRM_VCARD_ERR_VERSION');
	}

	public function GetVersion()
	{
		return $this->version;
	}

	public function GetCard()
	{
		if (isset($this->arCard['NAME']) && empty($this->arCard['NAME']) &&
			isset($this->arCard['LAST_NAME']) && empty($this->arCard['LAST_NAME']) &&
			isset($this->arEmail[0]))
		{
			$this->arCard['NAME'] = $this->arEmail[0];
		}
		return $this->arCard;
	}

	private function ChangeFormat($input)
	{
		if ($this->getVersion() == '2.1')
			return str_replace('\;', ';', quoted_printable_decode($input));
		else if ($this->getVersion() == '3.0')
			return str_replace('\;', ';', str_replace('\,', ',', $input));
	}
}
?>