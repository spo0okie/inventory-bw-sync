<?php
/*
 * Общая логика синхронизации Инвентори -> VW: вычисление желаемого состояния коллекции
 * (имя, состав ACL) по сервису из инвентори. Используется sync.php (применение изменений)
 * и diff.php (отчет об ожидающих изменениях) - чтобы diff считался тем же кодом что и sync.
 *
 * Ожидает глобальные $bw (bwApi), $inventory (inventoryApi) и константы ORG_ID, COL_ROOT.
 */

/**
 * Пользователь - владелец/админ организации: доступ ко всем коллекциям у него и так есть.
 * Старые VW помечали таких флагом accessAll, новые (Flexible Collections) флаг убрали
 * и отдают админов явными записями в ACL каждой коллекции - поэтому дополнительно
 * проверяем роль (type: 0=owner, 1=admin). В синхронизации ACL таких не учитываем.
 */
function isOrgAdmin($user) {
	if (!is_array($user)) return false;
	if (!empty($user['accessAll'])) return true;	//старые версии VW
	return ($user['type']??2)<=1;					//0=owner, 1=admin
}

function addTeammate(&$team,$mate) {
	if (!is_array($mate)) return;
	if (!isset($mate['Login'])) return;
	$team[$mate['Login']]=$mate;
}

function addTeammates(&$team,$mates) {
	if (!is_array($mates)) return;
	foreach ($mates as $mate) {
		addTeammate($team,$mate);
	}
}

function serviceTeam($service) {
	$team=[];
	addTeammate($team,$service['responsibleRecursive']);
	addTeammate($team,$service['infrastructureResponsibleRecursive']);
	addTeammates($team,$service['supportRecursive']);
	addTeammates($team,$service['infrastructureSupportRecursive']);
	return $team;
}

function inventoryTeam2Bw($team) {
	global $bw;
	$users=[];
	foreach ($team as $mate) {
		$mail=strtolower(arrHelper::getField($mate,'Email',''));
		$user=$bw->findUser(ORG_ID,['email'=>$mail]);
		//echo $mail.' ';
		//print_r($user);
		if (is_array($user) && !isOrgAdmin($user)) {
			$users[$user['id']]=$user;
		}
	}
	return $users;
}

function serviceName($service,$postfix='')
{
	global $inventory;
	$name=trim($service['nameWithoutParent']);
	if ($postfix) $name.='/'.$postfix;
	if ($service['parent_id']) {
		if (is_array($parent=$inventory->getService($service['parent_id']))) {
			return serviceName($parent,$name);
		}
	}
	return $name;
}

function colParams($service) {
	$team=serviceTeam($service);
	if (!count($team)) {
		echo " - У сервиса нет команды в инвентори!\n";
		return null;
	}
	$users=inventoryTeam2Bw($team);
	if (!count($users)) {
		echo " - У сервиса нет команды в VW! :".implode(", ",array_keys($team))."\n";
		return null;
	}
	$access=[];
	foreach (array_keys($users) as $id) {
		$access[]=[
			'id'=>$id,
			"readOnly"=>false,
			"hidePasswords"=>false,
 			"manage"=>false
		];
	}
	return [
		"organizationId"=>ORG_ID,
		"name"=>COL_ROOT."/".serviceName($service),
		"externalId"=>'inventory#'.$service['id'],
		"users"=>$access,
		"groups"=>[],
	];
}

/**
 * Пользователи из ACL коллекции с фильтрацией админов и осиротевших записей
 * (то что реально участвует в синхронизации)
 * @param $col array коллекция (реальная или из colParams)
 * @return array [id=>user]
 */
function aclUsers($col) {
	global $bw;
	$users=[];
	foreach ($col['users']??[] as $ace) {
		$user=$bw->findUser(ORG_ID,['id'=>$ace['id']]);
		if (!is_array($user)) continue;		//осиротевшая запись ACL
		if (isOrgAdmin($user)) continue;	//у админов доступ и так есть
		$users[$user['id']]=$user;
	}
	return $users;
}
