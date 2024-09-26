<?php
/**/

require_once 'lib_arrHelper.php';

class inventoryApi {
	public $cache=[];
	public $apiUrl=null;
	public $auth=null;
	public $context=null;


	public function init($url,$auth) {
		$this->apiUrl=$url;
		$this->auth=$auth;
		$this->context=stream_context_create([
				"http" => [
				"header" => "Authorization: Basic $auth"
			],
			"ssl"=>[
				"verify_peer"=>false,
				"verify_peer_name"=>false,
			],
		]);
	}

	public function req($path) {
		return file_get_contents($this->apiUrl.$path,false,$this->context);
	}

	public function getCache($model,$id,$default=null) {
		return $this->cache[$model][$id]??$default;
	}

	public function setCache($model,$id,$value) {
		if (!isset($this->cache[$model])) $this->cache[$model]=[];
		$this->cache[$model][$id]=$value;
	}

	/**
	 * Собрать данные о сервисах
	 */
	public function cacheServices() {

		$data=$this->req('/api/services/?per-page=0&expand=infrastructureResponsibleRecursive,infrastructureSupportRecursive,supportRecursive,responsibleRecursive,nameWithoutParent');
		$obj=json_decode($data,true);
		foreach ($obj as $svc) {
			$this->setCache('services',$svc['id'],$svc);
		}
	}

	public function getServices() {return $this->cache['services']??[];}
	public function getService($id) {return $this->cache['services'][$id]??null;}

}


?>
