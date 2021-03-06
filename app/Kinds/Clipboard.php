<?php

namespace Zync\Kinds;

use Google\Cloud\Datastore\Entity;
use Zync\Helpers\Bucket;
use Zync\Helpers\Datastore;

class Clipboard {

	const CLIP_COUNT = 10;
	const EXPIRY_TIME_MIN = 60000; // 60 Seconds
	const EXPIRY_TIME_MAX = 300000; // 300 Seconds

	private static $kind = "clipboard";

	/**
	 * @var Entity
	 */
	private $data;

	private function __construct($data) {
		$this->data = $data;
	}

	/**
	 * @return Clipboard
	 */
	public static function findByUserID($id){
		$query = Datastore::get()->query()
			->kind(self::$kind)
			->filter("user", "=", $id);

		$result = Datastore::get()->runQuery($query);
		$clipboard = $result->current();

		if(is_null($clipboard)){
			return null;
		}

		return new Clipboard($clipboard);
	}

	/**
	 * @return Clipboard
	 */
	public static function create($userID, $data){
		$insert = [
			'user' => $userID,
			'clip_count' => 1,
			'latest' => $data["timestamp"],
			'clips' => [
				[
					"timestamp" => $data["timestamp"],
					"hash" => $data["hash"],
					"encryption" => $data["encryption"],
					"payload-type" => $data["payload-type"]
				]
			],
		];

		$key = Datastore::get()->key(self::$kind);
		$entity = Datastore::get()->entity($key, $insert);

		Datastore::get()->insert($entity);

		$insert["id"] = $entity->key()->pathEndIdentifier();
		return new Clipboard($entity);
	}

	/**
	 * @return Entity
	 */
	public function getData(){
		return $this->data;
	}

	public function save(){
		$transaction = Datastore::get()->transaction();
		$transaction->upsert($this->data);
		$transaction->commit();
	}

	public function saveContents($data, $timestamp){
		$path = $this->getHexPath($timestamp);

		$file = Bucket::get()->upload($data, [
			"name" => $path
		]);

		return $file;
	}

	/**
	 * @return string
	 */
	public function getHexPath($timestamp){
		$hex = dechex($this->data->key()->pathEndIdentifier());
		$padded = str_pad($hex, 14, "0");
		$path = implode("/", str_split($padded, 2));
		$path = "/data/clipboards/" . $path . "/" . $timestamp;
		return $path;
	}

	public function newClip($data){
		$clips = $this->data["clips"];

		if(count($clips) + 1 > Clipboard::CLIP_COUNT){
			$remove = 0;
			for($i = 0; $i < count($clips); $i++){
				if($clips[$i]["timestamp"] == $this->data["latest"]){
					$remove = $i;
					break;
				}
			}

			unset($clips[$remove]);

			try{
				Bucket::get()->delete([
					"name" => $this->getHexPath($this->data["latest"])
				]);
			}catch(\Exception $e){
			}

			$clips = array_values($clips);
		}

		array_push($clips, [
			"timestamp" => $data["timestamp"],
			"hash" => $data["hash"],
			"encryption" => $data["encryption"],
			"payload-type" => $data["payload-type"]
		]);

		$this->data["clips"] = $clips;
		$this->data["clip_count"] = $this->data["clip_count"] + 1;
		$this->data["latest"] = $data["timestamp"];
	}

	public function exists($crc32){
		foreach($this->data["clips"] as $timestamp => $clip){
			if($clip["hash"]["crc32"] == $crc32){
				return true;
			}
		}

		return false;
	}

	public function getHistory(){
		$history = [];
		$clips = $this->data["clips"];

		foreach($clips as $clip){
			array_push($history, [
				"timestamp" => $clip["timestamp"],
				"hash" => $clip["hash"],
				"encryption" => $clip["encryption"],
				"payload-type" => $clip["payload-type"]
			]);
		}

		return $history;
	}

	public function getLastClipboard(){
		$clips = $this->data["clips"];

		foreach($clips as $clip){
			if($clip["timestamp"] == $this->data["latest"]){
				return [
					"timestamp" => $this->data["latest"],
					"hash" => $clip["hash"],
					"encryption" => $clip["encryption"],
					"payload-type" => $clip["payload-type"],
					"payload" => Bucket::get()->object($this->getHexPath($this->data["latest"]))->downloadAsString()
				];
			}
		}

		return null;
	}

	public function getTimestampClipboard($timestamp){
		$clips = $this->data["clips"];

		foreach($clips as $clip){
			if($clip["timestamp"] == $timestamp){
				return [
					"timestamp" => $timestamp,
					"hash" => $clip["hash"],
					"encryption" => $clip["encryption"],
					"payload-type" => $clip["payload-type"],
					"payload" => Bucket::get()->object($this->getHexPath($timestamp))->downloadAsString()
				];
			}
		}

		return null;
	}

	public function getManyTimestampClipboards(array $timestamps){
		$clips = $this->data["clips"];

		$response = [];
		foreach($clips as $clip){
			if(in_array($clip["timestamp"], $timestamps)){
				array_push($response, [
					"timestamp" => $clip["timestamp"],
					"hash" => $clip["hash"],
					"encryption" => $clip["encryption"],
					"payload-type" => $clip["payload-type"],
					"payload" => Bucket::get()->object($this->getHexPath($clip["timestamp"]))->downloadAsString()
				]);
			}
		}

		if(count($response) != count($timestamps)){
			return null;
		}

		return $response;
	}

}