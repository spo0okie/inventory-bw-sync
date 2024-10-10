#!/usr/bin/php
<?php
/*
v1.4    + возможность расшарить пароль в коллекцию к которой нет доступа
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

$dryRun=!(array_search('real',$argv)!==false);
$verbose=(array_search('verbose',$argv)!==false);

function verboseMsg($msg) {
	global $verbose;
	if (!$verbose) return;
	echo $msg;
}

/**
 * Признак того что строка - запрос на добавление элемента в коллекцию
 * @param $string
 * @return bool
 */
function isShareRequest($string) {
    return substr($string,0,TAG_LENGTH)===SHARE_TAG;
}

/**
 * Возвращает токены стороки
 *       #share:<collection path> //Коллекция не найдена -> ['collection path','Коллекция не найдена'];
 * @param $string
 * @return array
 */
function getShareTokens($string) {
    $string=substr($string,TAG_LENGTH); //откусываем токен

    $comment='';
    if ($commentStart=strpos($string,'//')!==false) {
        $comment=substr($string,$commentStart+2);
        $string=trim(substr($string,0,$commentStart));
    }

    $path=trim($string);

    return [$path,$comment];
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

	$strings=explode("\n",$notes);

    foreach ($strings as $string) {
        if (isShareRequest($string)) {
            $path=getShareTokens($string)[0];
            $collection=$bw->findCollection(ORG_ID,['name'=>COL_ROOT.'/'.$path]);

            if (!is_array($collection)) {  //нет такого пути
                $item['notes']=mb_convert_encoding(//без этого json_encode бросает Malformed UTF-8 characters, possibly incorrectly encoded
                    str_replace($string,shareString($path,'Коллекция не найдена'),$notes),
                    'UTF-8', 'UTF-8'
                );
            } else {    //такой путь есть

                $item['notes']=implode("\n",arrHelper::exclude($strings,$string));   //убираем запрос из комментария

                $collectionIds=$item['collectionIds'];
                if (array_search($collection['id'],$collectionIds)===false) {   //такой коллекции у элемента нет
                    $collectionIds[]=$collection['id'];
                    $item['collectionIds']=$collectionIds; //обновляем коллекции
                }
            }

            $bw->updateItem($item);
        }
    }
}

/*echo "Initializin Inventory API ... ";
$inventory=new inventoryApi();
//$inventory->init($webInventory,$inventoryAuth);
//$inventory->cacheServices();
echo "complete\n";*/


echo "Initializin VW API ... ";
$bw=new bwApi($vwUrl,$vwLogin,$vwWebPassword,$vwCliPassword);
$bw->cache_collections(ORG_ID);
$bw->cache_items(ORG_ID);
echo "complete\n";



foreach ($bw->cache['items'] as $item) {
	parseItem($item);
}

