#!/usr/bin/php
<?php
/*
v1.9    + отчет об ожидающих подтверждения изменениях в HTML, ничего не меняет в VW

Использование:
    php diff.php              HTML в stdout, служебный вывод в stderr
    php diff.php report.html  HTML в файл, служебный вывод как обычно

Код возврата: 0 - изменений нет, 1 - есть изменения, >1 - ошибка

Поддеревья с одинаковыми изменениями сворачиваются (как $authorizedAcl в sync.php):
если у потомков тот же diff ACL (а переименование - следствие переименования предка),
они не рисуются отдельно, а считаются в "(+N субсервисов)" у верхнего сервиса ветки.
*/

/**
 * @var $webInventory string
 * @var $inventoryAuth string
 * @var $vwUrl string
 * @var $vwLogin string
 * @var $vwWebPassword string
 * @var $vwCliPassword string
 */

include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_inventoryApi.php';
require_once dirname(__FILE__).'/lib_bwApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';
require_once dirname(__FILE__).'/lib_sync.php';

//------------------------------------------------------------------ вычисление diff

/**
 * Подпись набора пользователей (для сравнения и группировки)
 */
function usersSig($users) {
	$mails=[];
	foreach ($users as $user) $mails[]=strtolower($user['email']);
	sort($mails);
	return implode(',',$mails);
}

/**
 * Отличия коллекции сервиса от желаемого состояния (то же, что сравнивает sync.php)
 * @return array|null null - изменений нет (или сервис не синхронизируется)
 */
function serviceDiff($service) {
	global $bw;

	$newCol=colParams($service);
	if (!is_array($newCol)) return null;	//нет команды - sync такое пропускает

	$col=$bw->findCollection(ORG_ID,['externalId'=>'inventory#'.$service['id']]);
	$newUsers=aclUsers($newCol);

	if (!is_array($col)) {	//коллекции еще нет - будет создана
		return [
			'type'=>'new',
			'name'=>$newCol['name'],
			'newUsers'=>$newUsers,
			'sig'=>usersSig($newUsers),
		];
	}

	$oldUsers=aclUsers($col);
	$renamed=$col['name']!==$newCol['name'];
	$aclChanged=usersSig($oldUsers)!==usersSig($newUsers);
	if (!$renamed && !$aclChanged) return null;

	return [
		'type'=>'change',
		'oldName'=>$col['name'],
		'name'=>$newCol['name'],
		'renamed'=>$renamed,
		'aclChanged'=>$aclChanged,
		'oldUsers'=>$oldUsers,
		'newUsers'=>$newUsers,
		'sig'=>$aclChanged ? usersSig($oldUsers).'>'.usersSig($newUsers) : '',
	];
}

/**
 * Можно ли свернуть diff потомка в запись предка (одинаковые изменения)
 */
function collapsible($diff,$entry) {
	if ($diff['type']!==$entry['type']) return false;

	if ($diff['type']==='new')	//новые ветки: один и тот же состав команды
		return $diff['sig']===$entry['sig'];

	if ($diff['sig']!==$entry['sig']) return false;	//разные изменения ACL

	if ($diff['renamed']) {
		//переименование потомка должно быть следствием переименования предка:
		//тот же префикс до и после, хвост пути не изменился
		if (!$entry['renamed']) return false;
		if (strpos($diff['oldName'],$entry['oldName'].'/')!==0) return false;
		if (strpos($diff['name'],$entry['name'].'/')!==0) return false;
		if (substr($diff['oldName'],strlen($entry['oldName']))!==substr($diff['name'],strlen($entry['name'])))
			return false;
	} elseif ($entry['renamed']) {
		return false;	//предок переименован, потомок нет - что-то самостоятельное, покажем отдельно
	}

	return true;
}

/**
 * Рекурсивный обход дерева сервисов со сбором записей отчета в $entries
 * @param $service array сервис из инвентори
 * @param $parentEntry int|null индекс записи предка, в которую можно сворачивать
 */
function walkService($service,$parentEntry=null) {
	global $inventory,$entries;

	if ($service['archived']) return;

	echo $service['name']."\n";	//прогресс (уйдет в stderr)

	$entryIdx=$parentEntry;
	$diff=serviceDiff($service);
	if (is_array($diff)) {
		if (!is_null($parentEntry) && collapsible($diff,$entries[$parentEntry])) {
			$entries[$parentEntry]['sub']++;
		} else {
			$diff['sub']=0;
			$entries[]=$diff;
			$entryIdx=count($entries)-1;
		}
	}
	//узлы без изменений группу не разрывают: их потомки могут свернуться в ту же запись
	foreach (arrHelper::getItemsByFields($inventory->getServices(),['parent_id'=>$service['id']]) as $child) {
		walkService($child,$entryIdx);
	}
}

//------------------------------------------------------------------ рендер HTML

function h($string) {return htmlspecialchars($string,ENT_QUOTES,'UTF-8');}

function pluralRu($n,$one,$few,$many) {
	$n=abs($n)%100;
	if ($n>=11 && $n<=14) return $many;
	if ($n%10==1) return $one;
	if ($n%10>=2 && $n%10<=4) return $few;
	return $many;
}

function userLabel($user) {
	$name=trim($user['name']??'');
	return strlen($name)?$name:$user['email'];
}

/**
 * Список пользователей с раскраской: остаются - как есть, убираемые - красным с "-",
 * добавляемые - зеленым с "+". Для новых коллекций ($old=null) все зеленым.
 */
function htmlUsers($old,$new) {
	$keep=[];$del=[];$add=[];
	foreach ($new as $id=>$user) {
		$label=h(userLabel($user));
		if (is_null($old)) $add[$label]='<span style="color:#1a7f37">+'.$label.'</span>';
		elseif (isset($old[$id])) $keep[$label]=$label;
		else $add[$label]='<span style="color:#1a7f37">+'.$label.'</span>';
	}
	if (!is_null($old)) foreach ($old as $id=>$user) {
		if (!isset($new[$id])) {
			$label=h(userLabel($user));
			$del[$label]='<span style="color:#b91c1c">&minus;'.$label.'</span>';
		}
	}
	ksort($keep);ksort($del);ksort($add);
	return implode(', ',array_merge($keep,$del,$add));
}

function htmlEntry($entry) {
	$sub=$entry['sub']
		? ' <span style="color:#666">(+'.$entry['sub'].' '.pluralRu($entry['sub'],'субсервис','субсервиса','субсервисов').')</span>'
		: '';

	if ($entry['type']==='new') {
		return '<div style="margin:8px 0;padding:8px 12px;border-left:4px solid #1a7f37;background:#f6fff8">'
			.'<b>Новая коллекция:</b> '.h($entry['name']).$sub
			.'<br>Доступ: '.htmlUsers(null,$entry['newUsers'])
			.'</div>';
	}

	$html='<div style="margin:8px 0;padding:8px 12px;border-left:4px solid '
		.($entry['aclChanged']?'#d97706':'#0969da').';background:#fafafa">';
	$html.=$entry['renamed']
		? '<b>Путь:</b> <span style="color:#b91c1c">'.h($entry['oldName']).'</span> &rarr; <span style="color:#1a7f37">'.h($entry['name']).'</span>'
		: '<b>'.h($entry['name']).'</b>';
	$html.=$sub;
	if ($entry['aclChanged'])
		$html.='<br>Доступ: '.htmlUsers($entry['oldUsers'],$entry['newUsers']);
	$html.='</div>';
	return $html;
}

function htmlReport($entries) {
	$date=date('Y-m-d H:i');
	$html='<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
		.'<title>Vaultwarden: ожидающие изменения</title></head>'
		.'<body style="font-family:sans-serif;font-size:14px;color:#222;margin:16px">';
	if (count($entries)) {
		$html.='<h2 style="margin:0 0 4px 0">Vaultwarden: изменения ожидают синхронизации</h2>'
			.'<p style="color:#666;margin:0 0 12px 0">'.$date.' &mdash; '
			.count($entries).' '.pluralRu(count($entries),'изменение','изменения','изменений')
			.' (изменения ACL применяются только после подтверждения в sync.php)</p>';
		foreach ($entries as $entry) $html.=htmlEntry($entry);
	} else {
		$html.='<h2 style="margin:0 0 4px 0">Vaultwarden: изменений нет</h2>'
			.'<p style="color:#666">'.$date.'</p>';
	}
	$html.='</body></html>';
	return $html;
}

//------------------------------------------------------------------ main

//весь консольный шум (инициализация, предупреждения, тайминги) уводим в stderr:
//в stdout должен попасть только HTML
ob_start();
register_shutdown_function(function() {
	//если скрипт умер по exit() внутри библиотек - буфер тоже отдаем в stderr
	if (ob_get_level()) fwrite(STDERR,ob_get_clean());
});

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

$entries=[];
foreach ($inventory->getServices() as $service) {
	//обходим от корневых сервисов
	if ($service['parent_id']) continue;
	walkService($service);
}
echo count($entries)." изменений\n";

fwrite(STDERR,ob_get_clean());	//шум - в stderr

$html=htmlReport($entries);
if (isset($argv[1])) {
	file_put_contents($argv[1],$html);
} else {
	echo $html;
}

exit(count($entries)?1:0);
