<?php

namespace Moometric;

class Helper {

  const ERROR_MSG = 'ERROR, see logs (browser console or folder logs).';

  public static function readConfigFile(string $config_file, object $logger) {
    $content = file_get_contents($config_file);
    if ( !empty($content) ) {
      $content = json_decode($content, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $logger->error('JSON data from user-data.json is incorrect: ' . json_last_error_msg());
        exit(self::ERROR_MSG);
      }
      return $content;
    } else {
      $logger->warning('user-data.json is empty');
      return [];
    }
  }

  public static function exit() {
    exit(self::ERROR_MSG);
  }
}
