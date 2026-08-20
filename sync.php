#!/usr/bin/php
<?php
/*
v1		+ поиск service в inventory
v1.1    + рабочая схема синхронизации коллекций и ACL
v1.2    ! bug fixes
v1.3    + учет наличия суперпользователей
v1.6    ! ускорение: base64 вместо bw encode, тайминги вызовов CLI, исправлены флаги JSON_ENCODE
v1.7    ! новые VW (Flexible Collections) отдают админов в ACL коллекций явно вместо accessAll -
          определяем их по роли (type), из сравнения ACL исключаем, при обновлении не затираем
v1.8    + все операции с вольтом через bw serve (локальный REST) вместо запуска CLI на каждую команду
v1.9    + общая логика вычисления состояния коллекций вынесена в lib_sync.php (используется также diff.php)
*/

/**
 * @var $webInventory string
 * @var $inventoryAuth string
 * @var $vwUrl string
 * @var $vwLogin string
 * @var $vwWebPassword string
 * @var $vwCliPassword string
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

include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_inventoryApi.php';
require_once dirname(__FILE__).'/lib_bwApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';
require_once dirname(__FILE__).'/lib_sync.php';	//общая логика: colParams, serviceTeam, isOrgAdmin, aclUsers...

function collectionUsersRender($collection) {
	$users=[];
	foreach (aclUsers($collection) as $user) {
		$users[]=$user['email'];
	}
	sort($users);
	return implode(' ',$users);
}

function compareCollections($old,$new) {
	$result=[];
	if ($old['name']!=$new['name']) $result['path']="\e[1;33;40mПуть:\e[0;37;40m \e[0;31;40m".$old['name']."\e[0;37;40m -> \e[1;32;40m".$new['name']."\e[0;37;40m\n";
	$oUsers=collectionUsersRender($old);
	$nUsers=collectionUsersRender($new);
	if ($oUsers != $nUsers) $result['acl']="\e[1;33;40mДоступ:\e[0;37;40m \e[0;31;40m".$oUsers."\e[0;37;40m -> \e[1;32;40m".$nUsers."\e[0;37;40m\n";
	return $result;
}

function renderCollection($collection) {
	echo "\e[1;33;40mПуть:\e[0;37;40m {$collection['name']}\n";
	echo "\e[1;33;40mДоступ:\e[0;37;40m ".collectionUsersRender($collection)."\n";
}

function renderCompare($compare) {
	echo $compare['path']??'';
	echo $compare['acl']??'';
}

function yn($question) {
	while (true) {
		$answer=readline($question);
		if ($answer=='y') return true;
		if ($answer=='n') return false;
	}
}

/**
 * @param $service array Сервис из инвентори для работы
 * @param $authorizedAcl string авторизованное ранее изменение доступа (переданное от родительской папки)
 *                       позволит при изменении прав на ветви не подтверждать отдельно каждое звено
 */
function parseService($service,$authorizedAcl='') {
	global $bw,$inventory;

	if ($service['archived']) return;

	echo $service['name']."\n";
	$col = $bw->findCollection(ORG_ID,['externalId'=>'inventory#'.$service['id']]);
	$newCol=colParams($service);
	if (is_array($newCol)) {
		if (is_array($col)) {
			//тут надо сравнивать $col и $newCol
			$compare=compareCollections($col,$newCol);
			if (count($compare)) {
				echo "Текущая конфигурация\n";
				renderCollection($col);
				echo "\e[1;37;40mИзменения для внесения:\e[0;37;40m\n";
				renderCompare($compare);
				if (!isset($compare['acl']) || $compare['acl']==$authorizedAcl || yn("Вносим изменения? (y/n):")) {
					$newCol=array_merge($col,$newCol);
					//возвращаем в ACL записи админов: в сравнении они не участвуют,
					//но затирать их при обновлении коллекции тоже не надо
					foreach ($col['users'] as $ace) {
						if (isOrgAdmin($bw->findUser(ORG_ID,['id'=>$ace['id']])))
							$newCol['users'][]=$ace;
					}
					echo "\e[1;37;40mОбновляем коллекцию\e[0;37;40m\n";
					renderCollection($newCol);
					$bw->updateCollection($newCol);
					//запоминаем авторизованное изменение доступа
					if (isset($compare['acl'])) $authorizedAcl=$compare['acl'];
				}
			} else {
				echo " - нет изменений\n";
			}
		} else {
            echo "\e[1;37;40mСоздаем коллекцию\e[0;37;40m\n";
            renderCollection($newCol);
            $bw->createCollection($newCol);
		}
	}
	$services=$inventory->getServices();
	foreach (arrHelper::getItemsByFields($services,['parent_id'=>$service['id']]) as $child) {
		parseService($child,$authorizedAcl);
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
