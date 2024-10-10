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
			/*
			var_dump(curl_getinfo($ch));
			var_dump($json);
			var_dump($ch);
			*/
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

        exec("bw sync");
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

        $data=exec("bw list items --session ".$this->session);
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
            .'echo \''.JSON_ENCODE($col,JSON_UNESCAPED_UNICODE && JSON_INVALID_UTF8_IGNORE).'\' | '
            .'bw encode | '
            .'bw edit org-collection --organizationid '.$col['organizationId'].' '.$col['id'];
        //echo $cmd."\n";
        exec($cmd);
        //$this->cache_collections($col['organizationId'],true);
    }

    public function updateItem($item) {
	    if (!isset($item['id'])) {
	        print_r($item);
	        return;
        }
	    $encoded = JSON_ENCODE($item,JSON_UNESCAPED_UNICODE && JSON_INVALID_UTF8_IGNORE);
	    if (!strlen($encoded)) {
            print_r($item);
            echo json_last_error_msg()."\n";
            return;
        }
        $cmd='export BW_SESSION='.$this->session.' && '
            .'echo \''.$encoded.'\' | '
            .'bw encode | '
            .'bw edit item '.$item['id'];
        //echo $cmd."\n";
        exec($cmd);
        $this->cache_items(true);
    }

}