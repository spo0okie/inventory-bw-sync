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
	public $cliError;
	public $cliExitCode;

	public function __construct($url,$login,$passWeb,$passCli) {
		$this->baseUrl=$url;
		$this->login=$login;
		$this->passwordWeb=$passWeb;
		$this->passwordCli=$passCli;
	}

	//выполняет команду 
	public function cliExec($cmd,$input='') {
		//дескрипторы
		$desc=[
			0 => ['pipe','r'],	//STDIN
			1 => ['pipe','w'],	//STDOUT
			2 => ['pipe','w'],	//STDERR
		];

		//переменные окружения
		$variables=array_merge($_SERVER,[
			'NODE_EXTRA_CA_CERTS'=>'/etc/ssl/certs/ca-certificates.crt',
			'NODE_TLS_REJECT_UNAUTHORIZED'=>0,
		]);
		if ($this->session) $variables['BW_SESSION']=$this->session;

		unset($variables['argv']);

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
			*/
		}
		$this->token=$json['access_token'];
		curl_close($ch);


		$this->cliExec($this->cliPath.' logout --quiet');
		$this->cliExec($this->cliPath." config server {$this->baseUrl} --quiet");
		$data=$this->cliExec($this->cliPath." login {$this->login} {$this->passwordCli} --raw");
		if (!strlen($data)) {
			echo "ERROR AUTHENTICATING VW CLI\n";
			exit;
		}

		$this->session=$data;
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

		$data=$this->cliExec($this->cliPath." list org-collections --organizationid ".$org_id);
		if (strlen($data)) {
			$collections=JSON_DECODE($data,true);
			$this->cache['collections']=$collections;
		} else {
			echo "Error loading CLI collections\n";
			exit;
		}

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
		$data=$this->cliExec($this->cliPath." list items");
		if (strlen($data)) {
			$items=JSON_DECODE($data,true);
			$this->cache['items']=$items;
		} else {
			echo "Error loading CLI items\n";
			exit;
		}
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
	    $jsonEncoded=JSON_ENCODE($col,JSON_UNESCAPED_UNICODE);
	    $bwEncoded=$this->cliExec($this->cliPath.' encode',$jsonEncoded);
        $this->cliExec($this->cliPath.' create org-collection --organizationid '.$col['organizationId'],$bwEncoded);
		//$this->cache_collections($col['organizationId'],true);
	}

	public function updateCollection($col) {
        $jsonEncoded=JSON_ENCODE($col,JSON_UNESCAPED_UNICODE);
        $bwEncoded=$this->cliExec($this->cliPath.' encode',$jsonEncoded);
        $this->cliExec($this->cliPath.' edit org-collection --organizationid '.$col['organizationId'].' '.$col['id'],$bwEncoded);
        //$this->cache_collections($col['organizationId'],true);
	}

	public function updateItem($item) {

        $jsonEncoded = JSON_ENCODE($item,JSON_UNESCAPED_UNICODE && JSON_INVALID_UTF8_IGNORE);
		if (!strlen($jsonEncoded)) {
			print_r($item);
			echo json_last_error_msg()."\n";
			return;
		}

        $bwEncoded=$this->cliExec($this->cliPath.' encode',$jsonEncoded);
        $this->cliExec($this->cliPath.' edit item '.$item['id'],$bwEncoded);
	}

	public function createItem($item) {
		if (isset($item['id'])) {
			unset($item['id']);
		}

		$encoded = JSON_ENCODE($item,JSON_UNESCAPED_UNICODE && JSON_INVALID_UTF8_IGNORE);
		if (!strlen($encoded)) {
			print_r($item);
			echo json_last_error_msg()."\n";
			return '';
		}

		$bwEncoded=$this->cliExec($this->cliPath.' encode',$encoded);
		//echo "$bwEncoded\n";

		$data=$this->cliExec($this->cliPath.' create item',$bwEncoded);
		if (strlen($data)) {
			$json=JSON_DECODE($data,true);
			return $json['id']??'';
		} else {
			echo "Error creating item (CLI interface)\n";
			return '';
		}
		//$this->cache_items(true);
	}

	public function deleteItem($item) {
		if (!isset($item['id'])) {
			echo "Cant delete item without ID set\n";
			return;
		}
		$this->cliExec($this->cliPath." delete item {$item['id']}");
		//$this->cache_items(true);
	}

}