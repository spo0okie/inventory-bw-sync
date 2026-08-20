<?php


class bwApi {
	public $session;	//сессия BW CLI
	public $token;		//токен для WEB API

	public $cache=[];
	public $baseUrl;
	public $login;
	public $passwordWeb;
	public $passwordCli;

	public $cliPath='/usr/local/bin/bw';
	public $showTimings=true;	//печатать длительность каждого вызова CLI/serve
	public $cliError;
	public $cliExitCode;

	public $servePort=8087;		//порт локального REST API (bw serve)
	public $serveProc=null;		//null - не запускали, false - используем чужой процесс, resource - наш

	public function __construct($url,$login,$passWeb,$passCli) {
		$this->baseUrl=$url;
		$this->login=$login;
		$this->passwordWeb=$passWeb;
		$this->passwordCli=$passCli;
	}

	//выполняет команду 
	//переменные окружения для запуска bw (разовые команды и serve)
	public function cliEnv() {
		$variables=array_merge($_SERVER,[
			'NODE_EXTRA_CA_CERTS'=>'/etc/ssl/certs/ca-certificates.crt',
			'NODE_TLS_REJECT_UNAUTHORIZED'=>0,
		]);
		if ($this->session) $variables['BW_SESSION']=$this->session;
		unset($variables['argv']);
		return $variables;
	}

	public function cliExec($cmd,$input='') {
		$t0=microtime(true);
		//дескрипторы
		$desc=[
			0 => ['pipe','r'],	//STDIN
			1 => ['pipe','w'],	//STDOUT
			2 => ['pipe','w'],	//STDERR
		];

		$variables=$this->cliEnv();

		//процесс
		$proc=proc_open(
			$cmd,		//command
			$desc,		//descriptors
			$pipes,		//pipes
			null,		//cwd
			$variables	//env_vars
		);

		if (is_resource($proc)) {
			if ($input) fwrite($pipes[0],$input);
			fclose($pipes[0]);

			$output=stream_get_contents($pipes[1]);
			fclose($pipes[1]);

			$this->cliError=stream_get_contents($pipes[2]);
			fclose($pipes[2]);

			$this->cliExitCode=proc_close($proc);

			if ($this->showTimings) {
				//аргументы не светим (в login передается пароль) - печатаем только имя команды
				$word=strtok(trim(substr($cmd,strlen($this->cliPath))),' ');
				printf("\t[bw %s: %.1fs]\n",$word,microtime(true)-$t0);
			}

			$this->cliShowIfError();
			return $output;
		}

		return false;
	}

	//показать результат выполнения команды выше
	public function cliShowError() {
		echo "({$this->cliExitCode}): {$this->cliError}\n";
	}

	//показать результат если есть ошибки
	public function cliShowIfError() {
		if ($this->cliExitCode) $this->cliShowError();
	}

	//выполнить команду в CLI
	public function cliCmd($cmd,$input='') {
		return $this->cliExec($this->cliPath.' '.$cmd,$input);
	}

	//получить структуру из выполнения команды
	public function cliGetJson($cmd,$input='') {
		$data=$this->cliCmd($cmd,$input);

		if (!strlen($data)) {
			echo "CLI ERROR: No data returned on CMD [$cmd]\n";
			exit (2);
		}

		$json=JSON_DECODE($data,true);
		if (!is_array($json)) {
			echo "CLI ERROR parsing data:$data\n";
			exit (3);
		}

		return $json;
	}

	/**
	 * Поднимаем локальный REST API (bw serve): один долгоживущий процесс с расшифрованным
	 * вольтом в памяти вместо запуска node (~1-2c) на каждую команду.
	 * ВНИМАНИЕ: порт слушает без аутентификации (bind только на 127.0.0.1)
	 */
	public function serveStart() {
		if (!is_null($this->serveProc)) return;

		//возможно serve уже висит с прошлого (упавшего) запуска - тогда используем его
		if (is_array($this->serveReq('GET','/status',null,true))) {
			echo "(найден работающий serve) ";
			$this->serveProc=false;	//не наш процесс - в деструкторе не убивать
			return;
		}

		$desc=[	//вывод никуда: если читать пайпы лень, они переполнятся и повесят процесс
			0 => ['file','/dev/null','r'],
			1 => ['file','/dev/null','a'],
			2 => ['file','/dev/null','a'],
		];
		$this->serveProc=proc_open(
			$this->cliPath." serve --hostname 127.0.0.1 --port {$this->servePort}",
			$desc,$pipes,null,$this->cliEnv()
		);

		//ждем готовности (до 10 секунд)
		for ($i=0;$i<50;$i++) {
			usleep(200000);
			if (is_array($this->serveReq('GET','/status',null,true))) return;
		}
		echo "ERROR: bw serve не поднялся на 127.0.0.1:{$this->servePort}\n";
		exit (5);
	}

	//запрос к bw serve; возвращает раскодированный ответ или false
	public function serveReq($method,$path,$body=null,$quiet=false) {
		$t0=microtime(true);
		$ch=curl_init();
		curl_setopt($ch, CURLOPT_URL,"http://127.0.0.1:{$this->servePort}".$path);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST,$method);
		if (!is_null($body)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS,JSON_ENCODE($body,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
			curl_setopt($ch, CURLOPT_HTTPHEADER,['Content-Type: application/json']);
		}
		$data=curl_exec($ch);
		curl_close($ch);
		if ($this->showTimings && !$quiet)
			printf("\t[serve %s %s: %.2fs]\n",$method,strtok($path,'?'),microtime(true)-$t0);
		if ($data===false) return false;
		$json=JSON_DECODE($data,true);
		return is_array($json)?$json:false;
	}

	//запрос к serve с контролем успеха; возвращает содержимое data
	//$fatal=false - при ошибке не прерываем скрипт (поведение старых операций записи), возвращаем null
	public function serveData($method,$path,$body=null,$fatal=true) {
		$json=$this->serveReq($method,$path,$body);
		if (!is_array($json) || empty($json['success'])) {
			echo "SERVE ERROR on [$method $path]: ".($json['message']??'нет ответа')."\n";
			if ($fatal) exit (6);
			return null;
		}
		return $json['data']??null;
	}

	public function __destruct() {
		//гасим наш процесс bw serve (чужой не трогаем)
		if (is_resource($this->serveProc)) {
			proc_terminate($this->serveProc);
			proc_close($this->serveProc);
		}
	}

	public function init_session() {
		if (!is_null($this->session)&&!is_null($this->token)) return;
		$form=[
			'scope'=>'api offline_access',
			'client_id'=>'web',
			'deviceType'=>9,
			'deviceIdentifier'=>'000',
			'deviceName'=>'cli',
			'grant_type'=>'password',
			'username'=>$this->login,
			'password'=>$this->passwordWeb,
		];

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$this->baseUrl.'/identity/connect/token');
		curl_setopt($ch, CURLOPT_POST,1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		//curl_setopt($ch, CURLOPT_VERBOSE,true);

		$data=curl_exec ($ch);
		$json=JSON_DECODE($data,true);
		if (!is_array($json) || !isset($json['access_token'])) {
			echo "ERROR AUTHENTICATING VW WEB\n";
			exit;
			/*
			var_dump(curl_getinfo($ch));
			var_dump($json);
			var_dump($ch);
			exit;
			/**/
		}
		$this->token=$json['access_token'];
		curl_close($ch);

		$status=$this->cliGetJson('status');
		if ($this->baseUrl !== $status['serverUrl']) {
			echo "configuring ... ";
			$this->cliCmd('logout --quiet');
			$this->cliCmd("config server {$this->baseUrl} --quiet");
			$status=$this->cliGetJson('status');
		}
		if (($status['status']??'ERROR')==='unauthenticated') {
			//залогиниться можно только разовой командой (у serve нет /login)
			echo "logging in ... ";
			$data=$this->cliCmd("login {$this->login} {$this->passwordCli} --raw");
			if (!strlen($data)) {
				echo "ERROR AUTHENTICATING VW CLI\n";
				exit;
			}
		}

		//дальше все операции с вольтом - через локальный REST (bw serve)
		echo "starting serve ... ";
		$this->serveStart();
		echo "unlocking ... ";
		$this->serveData('POST','/unlock',['password'=>$this->passwordCli]);
		$this->session='@serve';	//сессию держит процесс serve, тут лишь маркер что инициализация пройдена
		echo "syncing ... ";
		$this->serveData('POST','/sync');
	}

	public function getReq($path) {
		$this->init_session();
		$authorization = "Authorization: Bearer ".$this->token;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$this->baseUrl.$path);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json' , $authorization ]);
		$result=curl_exec($ch);
		curl_close($ch);
		return $result;
	}

	public function cache_collections($org_id,$force=false) {
		if (isset($this->cache['collections']) && !$force) return;

		$this->init_session();

		$collections=$this->serveData('GET','/list/object/org-collections?organizationId='.$org_id);
		$this->cache['collections']=$collections['data'];

		$data=$this->getReq('/api/organizations/'.$org_id.'/collections/details');
		if (strlen($data) && is_array($collections=JSON_DECODE($data,true)) && isset($collections['data'])) {
			$collections=JSON_DECODE($data,true);
			foreach($this->cache['collections'] as $i=>$collection ) {
				$additional=arrHelper::getItemByFields($collections['data'],['id'=>$collection['id']]);
				$this->cache['collections'][$i]['users']=$additional['users'];
				$this->cache['collections'][$i]['groups']=$additional['groups'];
			}
		} else {
			echo "Error loading WEB-API collections\n";
			exit;
		}
	}

	/*
	 {
	"continuationToken": null,
	"data": [
		{
			"accessAll": true,
			"collections": [],
			"email": "user@domain.tld",
			"externalId": null,
			"groups": [],
			"id": "11111111-2222-3333-4444-555555555555",
			"name": "somename",
			"object": "organizationUserUserDetails",
			"resetPasswordEnrolled": false,
			"status": 2,
			"twoFactorEnabled": false,
			"type": 0,
			"userId": "11111111-2222-3333-4444-555555555555" //не то же самое что ID
		},
	]}
	 */
	public function cache_users($org_id, $force=false) {
		if (isset($this->cache['users']) && !$force) return;
		$this->init_session();
		$data=$this->getReq('/api/organizations/'.$org_id.'/users');
		if (strlen($data) && is_array($json=JSON_DECODE($data,true)) && isset($json['data'])) {
			$this->cache['users']=[];
			foreach($json['data'] as $user ) {
				$this->cache['users'][$user['id']]=$user;
			}
			//print_r($collections['data']);
			//exit;
			//$this->cache['collections']=JSON_DECODE($data,true);
		} else {
			echo "Error loading WEB-API users\n";
			exit;
		}

		//print_r($this->cache);
	}

	public function cache_items($force=false) {
		if (isset($this->cache['items']) && !$force) return;

		$this->init_session();
		//sync уже сделан в init_session, второй раз не нужен
		$items=$this->serveData('GET','/list/object/items');
		$this->cache['items']=$items['data'];
	}

	public function findCollection($org_id,$filter) {
		$this->cache_collections($org_id);
		return arrHelper::getItemByFields($this->cache['collections'],$filter);
	}

	public function findUser($org_id,$filter) {
		$this->cache_users($org_id);
		return arrHelper::getItemByFields($this->cache['users'],$filter);
	}

	public function getCollectionUsers($col) {
		if (isset($col['users'])) return $col['users'];
		return null;
	}

	public function createCollection($col) {
		$this->serveData('POST','/object/org-collection?organizationId='.$col['organizationId'],$col,false);
		//$this->cache_collections($col['organizationId'],true);
	}

	public function updateCollection($col) {
		$this->serveData('PUT','/object/org-collection/'.$col['id'].'?organizationId='.$col['organizationId'],$col,false);
		//$this->cache_collections($col['organizationId'],true);
	}



	public function updateItem($item) {
		$this->serveData('PUT','/object/item/'.$item['id'],$item,false);
	}

	public function createItem($item) {
		if (isset($item['id'])) {
			unset($item['id']);
		}

		$data=$this->serveData('POST','/object/item',$item,false);
		return $data['id']??'';
		//$this->cache_items(true);
	}

	public function deleteItem($item) {
		if (!isset($item['id'])) {
			echo "Cant delete item without ID set\n";
			return;
		}
		$this->serveData('DELETE','/object/item/'.$item['id'],null,false);
		//$this->cache_items(true);
	}

}