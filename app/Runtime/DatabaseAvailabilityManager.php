<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class DatabaseAvailabilityManager
{
    private string $path;
    public function __construct(?string $path=null){$this->path=$path??RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE);}
    public function status():array { RuntimeConfiguration::ensure(); $value=JsonFileStore::load($this->path); if(($value['version']??null)!==1||!is_bool($value['available']??null))throw new RuntimeException('Invalid database availability state.'); return $value; }
    public function available():bool{return $this->status()['available'];}
    public function setAvailable(bool $available):array{$value=['version'=>1,'available'=>$available,'updatedAt'=>gmdate(DATE_ATOM)];JsonFileStore::save($this->path,$value);return $value;}
}
