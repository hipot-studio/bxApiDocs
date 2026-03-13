<?

namespace Bitrix\Intranet\Integration;

use Bitrix\Main\UserTable;

final class Main
{
	public static function onAfterIblockSectionUpdate($fields)
	{
		$iblockStructureId = \Bitrix\Main\Config\Option::get('intranet', 'iblock_structure', 0);
		if (
			$iblockStructureId <= 0 
			|| intval($fields['IBLOCK_ID']) != $iblockStructureId)
		{
			return true;
		}
		
		$iblockSectionId = intval($fields['ID']);
		
		$agents = \CAgent::GetList(array("ID"=>"DESC"), array("NAME" => "\Bitrix\Intranet\Integration\Main::reindexUserAgent(".$iblockSectionId."%"));
		while ($agent = $agents->Fetch())
		{
			\CAgent::Delete($agent['ID']);
		}
		
		\CAgent::AddAgent('\Bitrix\Intranet\Integration\Main::reindexUserAgent('.$iblockSectionId.');', "intranet", "Y", 10);
		
		return true;
	}
	
	public static function reindexUserAgent($iblockSectionId, $lastUserId = 0)
	{
		$iblockSectionId = intval($iblockSectionId);
		if (!$iblockSectionId)
			return '';

		$lastUserId = intval($lastUserId);

		$cursor = UserTable::query()
			->setSelect(['ID'])
			->where('REAL_USER', 'expr', true)
			->addFilter('UF_DEPARTMENT', $iblockSectionId)
			->addFilter('>ID', $lastUserId)
			->setLimit(100)
			->setOrder(['ID' => 'ASC'])
			->setOffset(0)
			->exec()
		;

		$found = false;
		while ($row = $cursor->fetch())
		{
			\Bitrix\Main\UserTable::indexRecord($row['ID']);

			$lastUserId = $row['ID'];
			$found = true;
		}

		if ($found)
		{
			return '\Bitrix\Intranet\Integration\Main::reindexUserAgent('.$iblockSectionId.', '.$lastUserId.');';
		}
		else
		{
			return '';
		}
	}
}