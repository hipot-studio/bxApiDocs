<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

class HcmLinkFieldAliasMap
{
	private static ?array $aliasToCode = null;

	private const CODE_TO_ALIAS = [
		'Imya' => 'Name',
		'Familiya' => 'LastName',
		'Otchestvo' => 'Patronymic',
		'FIOPolnoe_IM' => 'FullName',
		'FIOPolnoe_RD' => 'FullNameGen',
		'FIOPolnoe_DT' => 'FullNameDat',
		'FIOPolnoe_VN' => 'FullNameAcc',
		'FIOPolnoe_TV' => 'FullNameIns',
		'FIOPolnoe_PR' => 'FullNamePrep',
		'Finitials' => 'LastNameInitials',
		'RasshifrovkaPodpisi' => 'SignDecoded',
		'DataRozhdeniya' => 'Dob',
		'Pol' => 'Gender',
		'TelefonRabochiy' => 'WorkPhone',
		'AdresPoPropiske' => 'RegAddress',
		'ElektronnayaPochta' => 'Email',

		'NomerDogovora' => 'ContractNum',
		'DataDogovora' => 'ContractDate',
		'trudovayaFunktsiya' => 'JobFunction',
		'TabelnyyNomer' => 'EmpNum',
		'IspytatelnyySrok' => 'Probation',

		'DulSeriya' => 'IdDocSeries',
		'DulNomer' => 'IdDocNum',
		'DulDataVydachi' => 'IdDocIssueDate',
		'DulSrokDeystviya' => 'IdDocExpiry',
		'DulKemVydan' => 'IdDocIssuedBy',
		'DulKodMvd' => 'IdDocMvdCode',
		'DulKodPodrazdeleniya' => 'IdDocDeptCode',
		'DulStranaVydachi' => 'IdDocCountry',
		'DulPredstavlenie' => 'IdDocRepr',

		'Fot' => 'Payroll',
		'FotPropisyu' => 'PayrollWords',

		'Dolzhnost_IM' => 'Pos',
		'Dolzhnost_RD' => 'PosGen',
		'Dolzhnost_DT' => 'PosDat',
		'Dolzhnost_VN' => 'PosAcc',
		'Dolzhnost_TV' => 'PosIns',
		'Dolzhnost_PR' => 'PosPrep',

		'Podrazdelenie_IM' => 'Dept',
		'Podrazdelenie_RD' => 'DeptGen',
		'Podrazdelenie_DT' => 'DeptDat',
		'Podrazdelenie_VN' => 'DeptAcc',
		'Podrazdelenie_TV' => 'DeptIns',
		'Podrazdelenie_PR' => 'DeptPrep',

		'DolzhnostPoShtatnomuRaspisaniyu_IM' => 'StaffPos',
		'DolzhnostPoShtatnomuRaspisaniyu_RD' => 'StaffPosGen',
		'DolzhnostPoShtatnomuRaspisaniyu_DT' => 'StaffPosDat',
		'DolzhnostPoShtatnomuRaspisaniyu_VN' => 'StaffPosAcc',
		'DolzhnostPoShtatnomuRaspisaniyu_TV' => 'StaffPosIns',
		'DolzhnostPoShtatnomuRaspisaniyu_PR' => 'StaffPosPrep',

		'VidZanyatosti' => 'EmpType',
		'KolichestvoStavok' => 'StaffUnits',
		'VidDogovora' => 'ContractType',
		'GrafikRaboty' => 'WorkSchedule',
		'TarifnayaStavka' => 'TariffRate',

		'PriemNaRabotu' => 'Hire',
		'Uvolnenie' => 'Dismissal',
		'KadrovyyPerevod' => 'Transfer',
		'Komandirovka' => 'Trip',
		'Otpusk' => 'Vacation',
		'Otgul' => 'DayOff',
		'OtsutstvieSSokhraneniemOplaty' => 'PaidLeave',
		'RabotaVVykhodnoyDen' => 'WeekendWork',
		'KartochkaSotrudnika' => 'EmpCard',

		'DataPriemaNaRabotu' => 'HireDate',
		'DataUvolneniya' => 'DismissalDate',
		'DataKadrovogoPerevoda' => 'TransferDate',
		'DataNachalaKomandirovki' => 'TripStart',
		'DataOkonchaniyaKomandirovki' => 'TripEnd',
		'DataNachalaOtpuska' => 'VacationStart',
		'DataOkonchaniyaOtpuska' => 'VacationEnd',
		'DataNachalaOtgula' => 'DayOffStart',
		'DataOkonchaniyaOtgula' => 'DayOffEnd',
		'DataNachalaOtsutstviyaSSokhraneniemOplaty' => 'PaidLeaveStart',
		'DataOkonchaniyaOtsutstviyaSSokhraneniemOplaty' => 'PaidLeaveEnd',

		'NomerDokumentaPriemaNaRabotu' => 'DocNumHire',
		'NomerDokumentaUvolneniya' => 'DocNumDismissal',
		'NomerDokumentaKadrovogoPerevoda' => 'DocNumTransfer',
		'NomerDokumentaNaKomandirovku' => 'DocNumTrip',
		'NomerDokumentaNaOtpusk' => 'DocNumVacation',
		'NomerDokumentaOtgula' => 'DocNumDayOff',
		'NomerDokumentaOtsutstviyaSSokhraneniemOplaty' => 'DocNumPaidLeave',
		'NomerDokumentaRabotyVVykhodnoyDen' => 'DocNumWeekendWork',

		'DataDokumentaPriemaNaRabotu' => 'DocDateHire',
		'DataDokumentaUvolneniya' => 'DocDateDismissal',
		'DataDokumentaKadrovogoPerevoda' => 'DocDateTransfer',
		'DataDokumentaNaKomandirovku' => 'DocDateTrip',
		'DataDokumentaNaOtpusk' => 'DocDateVacation',
		'DataDokumentaOtgula' => 'DocDateDayOff',
		'DataDokumentaOtsutstviyaSSokhraneniemOplaty' => 'DocDatePaidLeave',
		'DataDokumentaRabotyVVykhodnoyDen' => 'DocDateWeekendWork',
	];

	public static function getCodeToAlias(): array
	{
		return self::CODE_TO_ALIAS;
	}

	public static function getAliasToCode(): array
	{
		return self::$aliasToCode ??= array_flip(self::CODE_TO_ALIAS);
	}
}
