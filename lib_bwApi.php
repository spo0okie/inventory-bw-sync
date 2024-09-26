<?php


class bwApi {
	public $session;	//сессия BW CLI
	public $token;		//токен для WEB API

	public $cache=[];
	public $baseUrl;
	public $login;
	public $passwordWeb;
	public $passwordCli;

	public function __construct($url,$login,$passWeb,$passCli) {
		$this->baseUrl=$url;
		$this->login=$login;
		$this->passwordWeb=$passWeb;
		$this->passwordCli=$passCli;
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
			var_dump(curl_getinfo($ch));
			var_dump($json);
			var_dump($ch);
		}
		$this->token=$json['access_token'];
		curl_close($ch);

		$data=exec(
			"NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt && "
			."NODE_TLS_REJECT_UNAUTHORIZED=0 && "
			."bw logout --quiet && "
			."bw config server {$this->baseUrl} --quiet && "
			."bw login {$this->login} {$this->passwordCli} --raw"
		);
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
		//curl_setopt($ch, CURLOPT_VERBOSE,true);
		$result=curl_exec($ch);
		curl_close($ch);
		return $result;
	}

	public function cache_collections($org_id,$force=false) {
		if (isset($this->cache['collections']) && !$force) return;

		$this->init_session();

		$data=exec("bw list org-collections --organizationid ".$org_id." --session ".$this->session);
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
			//print_r($collections['data']);
			//exit;
			//$this->cache['collections']=JSON_DECODE($data,true);
		} else {
			echo "Error loading WEB-API collections\n";
			exit;
		}
		//print_r($this->cache);
	}

	public function cache_users($org_id, $force=false) {
		if (isset($this->cache['users']) && !$force) return;
		$this->init_session();
		$data=exec("bw list org-members --organizationid ".$org_id." --session ".$this->session);
		if (strlen($data)) {
			$users=JSON_DECODE($data,true);
			foreach($users as $i=>$user) {
				$mail=arrHelper::getField($user,'Email','');
				$users['Email']=strtolower($mail);
			}
			$this->cache['users']=$users;
		} else {
			echo "Error loading CLI collections\n";
			exit;
		}

		//print_r($this->cache);
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
	}

	public function createCollection($col) {
		$cmd='export BW_SESSION='.$this->session.' && '
			.'echo \''.JSON_ENCODE($col,JSON_UNESCAPED_UNICODE).'\' | '
			.'bw encode | '
			.'bw create org-collection --organizationid '.$col['organizationId'];
		//echo $cmd."\n";
		exec($cmd);
		//$this->cache_collections($col['organizationId'],true);
	}

	public function updateCollection($col) {
		$cmd='export BW_SESSION='.$this->session.' && '
			.'echo \''.JSON_ENCODE($col,JSON_UNESCAPED_UNICODE).'\' | '
			.'bw encode | '
			.'bw edit org-collection --organizationid '.$col['organizationId'].' '.$col['id'];
		//echo $cmd."\n";
		exec($cmd);
		//$this->cache_collections($col['organizationId'],true);
	}

}