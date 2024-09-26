#!/usr/bin/php
<?php
/*
v1		+ поиск service в inventory
*/

/**
 * @var $webInventory string
 * @var $inventoryAuth string
 */

/*
ord-collection template:
{
    "organizationId": "00000000-0000-0000-0000-000000000000",
    "name": "Collection name",
    "externalId": null,
    "groups": [{
            "id": "00000000-0000-0000-0000-000000000000",
            "readOnly": false,
            "hidePasswords": false,
            "manage": false
        }, {
            "id": "00000000-0000-0000-0000-000000000000",
            "readOnly": false,
            "hidePasswords": false,
            "manage": false
        }
    ],
    "users": [{
            "id": "00000000-0000-0000-0000-000000000000",
            "readOnly": false,
            "hidePasswords": false,
            "manage": false
        }, {
            "id": "00000000-0000-0000-0000-000000000000",
            "readOnly": false,
            "hidePasswords": false,
            "manage": false
        }
    ]
}

*/
//$inventoryCache=[];
#require_once __DIR__ . '/vendor/autoload.php';

include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_inventoryApi.php';
require_once dirname(__FILE__).'/lib_bwApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';

#require_once __DIR__ . '/vendor/autoload.php';
#require_once __DIR__ . '/vendor/bitwarden/sdk/src/schemas/ClientSettings.php';
#print_r(get_declared_classes());

$dryRun=!(array_search('real',$argv)!==false);
$verbose=(array_search('verbose',$argv)!==false);

function verboseMsg($msg) {
	global $verbose;
	if (!$verbose) return;
	echo $msg;
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
		if (is_array($user)) {
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
		return;
	}
	$users=inventoryTeam2Bw($team);
	if (!count($users)) {
		echo " - У сервиса нет команды в VW! :".implode(", ",array_keys($team))."\n";
		return;
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

function collectionUsersRender($collection) {
	global $bw;
	$users=[];
	foreach ($collection['users'] as $ace) {
		$user=$bw->findUser(ORG_ID,['id'=>$ace['id']]);
		$users[]=$user['email'];
	}
	sort($users);
	return implode(' ',$users);
}

function compareCollections($old,$new) {
	$result='';
	if ($old['name']!=$new['name']) $result="\e[1;33;40mПуть:\e[0;37;40m \e[0;31;40m".$old['name']."\e[0;37;40m -> \e[1;32;40m".$new['name']."\e[0;37;40m\n";
	$oUsers=collectionUsersRender($old);
	$nUsers=collectionUsersRender($new);
	if ($oUsers != $nUsers) $result.="\e[1;33;40mДоступ:\e[0;37;40m \e[0;31;40m".$oUsers."\e[0;37;40m -> \e[1;32;40m".$nUsers."\e[0;37;40m\n";
	return $result;
}

function renderCollection($collection) {
	echo "\e[1;33;40mПуть:\e[0;37;40m {$collection['name']}\n";
	echo "\e[1;33;40mДоступ:\e[0;37;40m ".collectionUsersRender($collection)."\n";
}

function yn($question) {
	while (true) {
		$answer=readline($$auestion);
		if ($answer=='y') return true;
		if ($answer=='n') return false;
	}
}

function parseService($service) {
	global $bw,$inventory;

	if ($service['archived']) return;

	echo $service['name']."\n";
	$col = $bw->findCollection(ORG_ID,['externalId'=>'inventory#'.$service['id']]);
	$newcol=colParams($service);
	if (is_array($newcol)) {
		if (is_array($col)) {
			//тут надо сравнивать $col и $newcol
			$compare=compareCollections($col,$newcol);
			if (strlen($compare)) {
				echo "Текущая конфигурация\n";
				renderCollection($col);
				echo "\e[1;37;40mИзменения для внесения:\e[0;37;40m\n";
				echo $compare;
				if (strpos($compare,"\e[1;33;40mДоступ:\e[0;37;40m ")===false || yn("Вносим изменения? (y/n):")) {
					$newcol=array_merge($col,$newcol);
					echo "\e[1;37;40mОбновляем коллекцию\e[0;37;40m\n";
					renderCollection($newcol);
					$bw->updateCollection($newcol);
				}
			} else {
				echo " - нет изменений\n";
			}
		} else {
			if (is_array($newcol)) {
				//print_r($newcol);
				echo "\e[1;37;40mСоздаем коллекцию\e[0;37;40m\n";
				renderCollection($newcol);
				$bw->createCollection($newcol);
			} else {
				return;
			}
		}
	}
	$services=$inventory->getServices();
	foreach (arrHelper::getItemsByFields($services,['parent_id'=>$service['id']]) as $child) {
		parseService($child);
	}

}

echo "Initializin Inventory API ... ";
$inventory=new inventoryApi();
$inventory->init($webInventory,$inventoryAuth);
$inventory->cacheServices();
echo "complete\n";

echo "Initializin VW API ... ";
$bw=new bwApi($vwUrl,$vwLogin,$vwWebPassword,$vwCliPassword);
$bw->cache_collections(ORG_ID);
$bw->cache_users(ORG_ID);
echo "complete\n";


$services=$inventory->getServices();

foreach ($services as $service) {
	//пропускаем некорневые сервисы
	if ($service['parent_id']) continue;
	parseService($service);
}
?>
