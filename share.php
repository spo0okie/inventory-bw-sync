#!/usr/bin/php
<?php
/*
 * v1.5 ! исправлена проблема экранирования символов при передаче данных в bw cli через stdinput
 *      ! при пересоздании
 * v1.4	+ возможность расшарить пароль в коллекцию к которой нет доступа
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
Задача скрипта:
  * Найти элементы у которых в поле notes есть строки вида:
	  #share:<collection path>
  * Добавить этот элемент в коллекции с таким путем
  * Удалить соотв строки в случае успеха
  * Добавить комментарий в случае неудачи
	  #share:<collection path> //Коллекция не найдена
*/

const SHARE_TAG='#share:';
const TAG_LENGTH=7;

include dirname(__FILE__).'/config.priv.php';
//require_once dirname(__FILE__).'/lib_inventoryApi.php';
require_once dirname(__FILE__).'/lib_bwApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';

/**
 * Признак того что строка - запрос на добавление элемента в коллекцию
 * @param $string
 * @return bool
 */
function isShareRequest($string) {
	return substr($string,0,TAG_LENGTH)===SHARE_TAG;
}

function unicode_trim ($str) {
    return preg_replace('/^[\pZ\pC]+([\PZ\PC]*)[\pZ\pC]+$/u', '$1', $str);
}

/**
 * Возвращает токены строки
 *	   #share:<collection path> //Коллекция не найдена -> ['collection path','Коллекция не найдена'];
 * @param $string
 * @return array
 */
function getShareTokens($string) {
	$string=mb_substr($string,TAG_LENGTH); //откусываем токен

	$comment='';
	//проверяем, что у инструкции поделиться нет примечания (уже была неудачная попытка)
	$commentStart=mb_strpos($string,' //');
	if ($commentStart!==false) {
		//echo $string;
		//echo $commentStart;
		$comment=mb_substr($string,$commentStart+3);
		$string=mb_substr($string,0,$commentStart);
	}

	$path=unicode_trim($string);

	return $path;//[,$comment];
}


/**
 * Возвращает строку "#share:<collection path> //Коллекция не найдена"
 * @param $path
 * @param $comment
 * @return string
 */
function shareString($path,$comment='') {
	$string=SHARE_TAG.$path;
	if (strlen($comment))
		$string.=' //'.$comment;
	return $string;
}

/**
 * @param $item array Пароль из VW для обработки
 */
function parseItem($item) {
	global $bw; //,$inventory;

	$notes=trim($item['notes'])??'';
	if (!strlen($notes)) return;
	//echo "$notes\n";

	$strings=explode("\n",$notes);

	$needUpdate=false;
	$needShare=false;

	foreach ($strings as $i=>$string) {
		if (isShareRequest($string)) {
			$path=getShareTokens($string);
			//echo bin2hex($path)."\n";
			//echo bin2hex(COL_SHARED)."\n";
			//var_dump(strpos($path,COL_SHARED));
			$collectionPath=mb_strpos($path,COL_SHARED)===0?	//если путь начинается с общей папки, 
				$path:							//то сохраняем путь
				COL_ROOT.'/'.$path;				//иначе складываем в подпапку "Сервисы"

			echo "got request to share {$item['name']} -> $collectionPath\n";
			$collection=$bw->findCollection(ORG_ID,['name'=>$collectionPath]);

			if (!is_array($collection)) {  //нет такого пути
				$strings[$i]=shareString($path,'Коллекция не найдена');
				$needUpdate=true;
			} else {	//такой путь есть

				unset($strings[$i]);
				$needUpdate=true;

				$collectionIds=$item['collectionIds'];
				if (array_search($collection['id'],$collectionIds)===false) {   //такой коллекции у элемента нет
					$collectionIds[]=$collection['id'];
					$item['collectionIds']=$collectionIds; //обновляем коллекции
					$needShare=true;
				}
			}

		}
	}

	if ($needUpdate || $needShare) {
		echo "Updating item {$item['name']}\n";
		$item['notes']=implode("\n",$strings);
	}

	if ($needShare) {
		if (strlen($bw->createItem($item)))	//новый будет создан с другим ИД
			$bw->deleteItem($item);			//удален будет с текущим ИД
	} elseif ($needUpdate) {
		$bw->updateItem($item);
	}
}

/*echo "Initializin Inventory API ... ";
$inventory=new inventoryApi();
//$inventory->init($webInventory,$inventoryAuth);
//$inventory->cacheServices();
echo "complete\n";*/

$date = date('Y-m-d H:i:s', time());
echo "Script started at $date\n";
echo "Initializin VW API ... ";
$bw=new bwApi($vwUrl,$vwLogin,$vwWebPassword,$vwCliPassword);
$bw->cache_collections(ORG_ID);
$bw->cache_items(ORG_ID);
echo "complete\n";



foreach ($bw->cache['items'] as $item) {
	parseItem($item);
}

$date = date('Y-m-d H:i:s', time());
echo "all done. at $date\n";